<?php

declare(strict_types=1);

namespace Modules\Laboratory\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Numbering\NumberGenerator;
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
use Modules\Laboratory\Models\Transfer;
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
            if ($sample->isSentAway()) {
                throw new LabException('This sample is tested at '.Branch::find($sample->labBranchId())?->name.'. Collect it, then send it there with the other samples.');
            }

            Gate::authorize(Permissions::SAMPLES_RECEIVE, $sample->labBranchId());
        }

        $sample = DB::connection('clinic')->transaction(function () use ($sample, $alsoReceive): Sample {
            $sample = $this->lock($sample);
            $this->move($sample, SampleStatus::Collected, ['collected_by' => Auth::id(), 'collected_at' => now(), 'rejection_reason' => null]);

            return $alsoReceive ? $this->markReceived($sample) : $sample;
        });

        event(new SampleCollected($sample));

        return $sample;
    }

    /**
     * Record that the sample has arrived in the laboratory that tests it.
     * Its tests can now be worked on. A sample sent from a collection centre
     * is received by that laboratory, and its transfer is closed when its
     * last sample arrives.
     */
    public function receive(Sample $sample): Sample
    {
        Gate::authorize(Permissions::SAMPLES_RECEIVE, $sample->labBranchId());

        return DB::connection('clinic')->transaction(function () use ($sample): Sample {
            $sample = $this->lock($sample);

            if (! in_array($sample->status, [SampleStatus::Collected, SampleStatus::InTransit], true)) {
                throw new LabException('Only a collected sample can be received. Mark it collected first.');
            }

            $sample = $this->markReceived($sample);
            $this->closeTransfer($sample->transfer_id);

            return $sample;
        });
    }

    /**
     * Send collected samples from a collection centre to the laboratory
     * that tests them, as one numbered transfer with a list for the courier.
     *
     * @param  list<int>|null  $sampleIds  only these; null sends every collected sample waiting to go there
     */
    public function send(Branch $from, Branch $to, ?array $sampleIds = null, ?string $note = null): Transfer
    {
        Gate::authorize(Permissions::SAMPLES_COLLECT, $from->id);

        return DB::connection('clinic')->transaction(function () use ($from, $to, $sampleIds, $note): Transfer {
            $samples = Sample::where('branch_id', $from->id)
                ->where('lab_branch_id', $to->id)
                ->where('status', SampleStatus::Collected->value)
                ->when($sampleIds !== null, fn ($q) => $q->whereIn('id', $sampleIds))
                ->lockForUpdate()->orderBy('id')->get();

            if ($from->id === $to->id || $samples->isEmpty()) {
                throw new LabException("No collected sample is waiting to go to {$to->name}.");
            }

            $transfer = Transfer::create([
                'transfer_no' => app(NumberGenerator::class)->generate('sample_transfer', $from),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => 'in_transit',
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
                'sent_by' => Auth::id(),
                'sent_at' => now(),
            ]);

            foreach ($samples as $sample) {
                $this->move($sample, SampleStatus::InTransit, ['transfer_id' => $transfer->id], "Sent to {$to->name} in {$transfer->transfer_no}");
            }

            $this->audit->record('samples.sent', $transfer, [], [
                'transfer_no' => $transfer->transfer_no, 'from' => $from->name, 'to' => $to->name,
                'samples' => $samples->pluck('accession_no')->all(),
            ]);

            return $transfer;
        });
    }

    /** Receive every sample of a transfer that is still on its way. @return int how many were received */
    public function receiveTransfer(Transfer $transfer): int
    {
        Gate::authorize(Permissions::SAMPLES_RECEIVE, $transfer->to_branch_id);

        $samples = Sample::where('transfer_id', $transfer->id)->where('status', SampleStatus::InTransit->value)->get();

        foreach ($samples as $sample) {
            $this->receive($sample);
        }

        return $samples->count();
    }

    private function closeTransfer(?int $transferId): void
    {
        if ($transferId === null) {
            return;
        }

        $open = Sample::where('transfer_id', $transferId)->where('status', SampleStatus::InTransit->value)->exists();

        if (! $open) {
            Transfer::whereKey($transferId)->where('status', 'in_transit')->update(['status' => 'received', 'received_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reject an unusable sample (clotted, too little, wrong tube). Its
     * tests wait for a new collection, and any results entered from it but
     * not yet verified are discarded.
     */
    public function reject(Sample $sample, string $reason): Sample
    {
        // The centre may reject before sending; the laboratory after receiving.
        if (! Gate::allows(Permissions::SAMPLES_REJECT, $sample->branch_id)) {
            Gate::authorize(Permissions::SAMPLES_REJECT, $sample->labBranchId());
        }

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
            $this->closeTransfer($sample->transfer_id);

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
