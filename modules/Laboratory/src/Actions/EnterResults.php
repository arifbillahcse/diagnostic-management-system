<?php

declare(strict_types=1);

namespace Modules\Laboratory\Actions;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabException;
use Modules\Laboratory\Support\Progress;
use Modules\Laboratory\Support\ResultBuilder;

/**
 * Saves the values typed for one test.
 *
 * $values is [analyte id => ['value' => text, 'comment' => text]]. An empty
 * value clears that analyte. With $submit, every required value must be
 * present and the test moves on to verification.
 *
 * $lockVersion is the work item's lock_version as it was when the form was
 * opened. If someone else has saved since, the save is refused so nothing
 * is silently overwritten.
 */
final class EnterResults
{
    public function __construct(
        private readonly ResultBuilder $builder,
        private readonly Progress $progress,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<int|string, array{value?: ?string, comment?: ?string}> $values */
    public function handle(WorkItem $workItem, array $values, int $lockVersion, bool $submit = false): WorkItem
    {
        Gate::authorize(Permissions::RESULTS_ENTER, $workItem->branch_id);

        $firstEntry = false;

        $workItem = DB::connection('clinic')->transaction(function () use ($workItem, $values, $lockVersion, $submit, &$firstEntry): WorkItem {
            $item = WorkItem::whereKey($workItem->id)->lockForUpdate()->firstOrFail();

            if (! $item->status->isEditable()) {
                throw new LabException(match ($item->status) {
                    WorkStatus::AwaitingSample => 'The sample for this test has not reached the laboratory yet.',
                    WorkStatus::Entered => 'These results are waiting for verification. Ask the verifier to send them back to change them.',
                    WorkStatus::Cancelled => 'This test was cancelled.',
                    default => 'These results are verified and locked. Use "Amend" to correct them.',
                });
            }

            if ($item->lock_version !== $lockVersion) {
                throw new LabException('Someone else has changed these results since you opened them. Reload the page and enter your changes again.');
            }

            $patient = $item->patient;
            $collectedOn = $item->sample?->collected_at ?? now();
            $analytes = $item->labTest->analytes()->with('ranges')->get();
            $existing = $item->results()->get()->keyBy('analyte_id');
            $firstEntry = $existing->isEmpty();

            $before = $existing->mapWithKeys(fn (Result $r): array => [$r->analyte_name => $r->value_text])->all();
            $after = [];
            $missing = [];

            foreach ($analytes as $analyte) {
                $typed = trim((string) ($values[$analyte->id]['value'] ?? ''));

                if ($typed === '') {
                    $existing->get($analyte->id)?->delete();

                    if ($analyte->pivot->is_required) {
                        $missing[] = $analyte->name;
                    }

                    continue;
                }

                $comment = trim((string) ($values[$analyte->id]['comment'] ?? ''));

                if (mb_strlen($comment) > 255) {
                    throw new LabException("The comment for {$analyte->name} is longer than 255 characters.");
                }

                $built = $this->builder->build($analyte, $typed, $patient, $collectedOn);

                Result::updateOrCreate(
                    ['work_item_id' => $item->id, 'analyte_id' => $analyte->id],
                    $built + [
                        'analyte_name' => $analyte->name,
                        'result_type' => $analyte->result_type,
                        'group_label' => $analyte->pivot->group_label,
                        'sort_order' => $analyte->pivot->sort_order,
                        'comment' => $comment === '' ? null : $comment,
                    ],
                );

                $after[$analyte->name] = $built['value_text'].($built['flag']->isAbnormal() ? ' ['.$built['flag']->label().']' : '');
            }

            if ($submit && $missing !== []) {
                throw new LabException('Enter these before submitting: '.implode(', ', $missing).'.');
            }

            if ($submit && $after === []) {
                throw new LabException('There are no results to submit.');
            }

            $item->forceFill([
                'status' => $submit ? WorkStatus::Entered : $item->status,
                'entered_by' => Auth::id(),
                'entered_at' => now(),
                'lock_version' => $item->lock_version + 1,
            ])->save();

            [$was, $is] = $this->audit->diff($before, $after);

            if ($is !== [] || $was !== [] || $submit) {
                $this->audit->record($submit ? 'results.submitted' : 'results.saved', $item, $was, $is, [
                    'test' => $item->test_name,
                    'order_id' => $item->order_id,
                ]);
            }

            return $item;
        });

        if ($firstEntry) {
            $this->progress->refreshSample($workItem->sample);
            $this->progress->started($workItem->order);
        }

        return $workItem;
    }
}
