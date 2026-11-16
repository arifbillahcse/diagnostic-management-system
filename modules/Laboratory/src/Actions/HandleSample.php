<?php

declare(strict_types=1);

namespace Modules\Laboratory\Actions;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Events\SampleCollected;
use Modules\Laboratory\Events\SampleRejected;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\SampleEvent;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabException;

/**
 * Moves a sample through collection, receipt in the laboratory, and
 * rejection.
 */
final class HandleSample
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record that the sample was taken from the patient. When the sample is
     * taken inside the laboratory, pass $alsoReceive to do both steps.
     */
    public function collect(Sample $sample, bool $alsoReceive = false): Sample
    {
        Gate::authorize(Permissions::SAMPLES_COLLECT, $sample->branch_id);

        if ($alsoReceive) {
            Gate::authorize(Permissions::SAMPLES_RECEIVE, $sample->branch_id);
        }

        $sample = DB::connection('clinic')->transaction(function () use ($sample, $alsoReceive): Sample {
            $sample = $this->lock($sample);
            $this->move($sample, SampleStatus::Collected, ['collected_by' => Auth::id(), 'collected_at' => now(), 'rejection_reason' => null]);

            return $alsoReceive ? $this->markReceived($sample) : $sample;
        });

        event(new SampleCollected($sample));

        return $sample;
    }

    /** Record that the sample has arrived in the laboratory. Its tests can now be worked on. */
    public function receive(Sample $sample): Sample
    {
        Gate::authorize(Permissions::SAMPLES_RECEIVE, $sample->branch_id);

        return DB::connection('clinic')->transaction(function () use ($sample): Sample {
            $sample = $this->lock($sample);

            if ($sample->status !== SampleStatus::Collected) {
                throw new LabException('Only a collected sample can be received. Mark it collected first.');
            }

            return $this->markReceived($sample);
        });
    }

    /**
     * Reject an unusable sample (clotted, too little, wrong tube). Its
     * tests wait for a new collection, and any results entered from it but
     * not yet verified are discarded.
     */
    public function reject(Sample $sample, string $reason): Sample
    {
        Gate::authorize(Permissions::SAMPLES_REJECT, $sample->branch_id);

        if (blank($reason)) {
            throw new LabException('A reason is required to reject a sample.');
        }

        $sample = DB::connection('clinic')->transaction(function () use ($sample, $reason): Sample {
            $sample = $this->lock($sample);
            $items = WorkItem::where('sample_id', $sample->id)->lockForUpdate()->get();

            $signedOff = $items->filter(fn (WorkItem $w): bool => $w->version > 0 && $w->status !== WorkStatus::Cancelled);

            if ($signedOff->isNotEmpty()) {
                throw new LabException('Results from this sample have already been verified ('.$signedOff->pluck('test_name')->implode(', ').'). Amend those results instead of rejecting the sample.');
            }

            $this->move($sample, SampleStatus::Rejected, ['rejection_reason' => trim($reason)], trim($reason));

            foreach ($items->whereIn('status', [WorkStatus::Ready, WorkStatus::Entered]) as $item) {
                $discarded = $item->results()->get()->mapWithKeys(fn (Result $r): array => [$r->analyte_name => $r->value_text])->all();

                Result::where('work_item_id', $item->id)->delete();

                $item->forceFill([
                    'status' => WorkStatus::AwaitingSample,
                    'entered_by' => null,
                    'entered_at' => null,
                    'pending_reason' => 'Sample rejected: '.trim($reason),
                    'lock_version' => $item->lock_version + 1,
                ])->save();

                if ($discarded !== []) {
                    $this->audit->record('results.discarded', $item, $discarded, [], ['reason' => 'Sample '.$sample->accession_no.' rejected: '.trim($reason)]);
                }
            }

            return $sample;
        });

        event(new SampleRejected($sample));

        return $sample;
    }

    private function markReceived(Sample $sample): Sample
    {
        $this->move($sample, SampleStatus::Received, ['received_by' => Auth::id(), 'received_at' => now()]);

        WorkItem::where('sample_id', $sample->id)
            ->where('status', WorkStatus::AwaitingSample->value)
            ->update(['status' => WorkStatus::Ready->value, 'pending_reason' => null, 'updated_at' => now()]);

        return $sample;
    }

    private function lock(Sample $sample): Sample
    {
        return Sample::whereKey($sample->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $changes */
    private function move(Sample $sample, SampleStatus $next, array $changes = [], ?string $note = null): void
    {
        if (! $sample->status->canBecome($next)) {
            throw new LabException("A sample that is \"{$sample->status->label()}\" cannot become \"{$next->label()}\".");
        }

        $previous = $sample->status;
        $sample->forceFill(['status' => $next] + $changes)->save();

        SampleEvent::create(['sample_id' => $sample->id, 'event' => $next->value, 'actor_id' => Auth::id(), 'occurred_at' => now(), 'note' => $note]);

        $this->audit->record('sample.'.$next->value, $sample, ['status' => $previous->value], ['status' => $next->value, 'accession_no' => $sample->accession_no], $note ? ['reason' => $note] : []);
    }
}
