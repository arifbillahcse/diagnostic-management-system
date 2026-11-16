<?php

declare(strict_types=1);

namespace Modules\Laboratory\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Events\ResultsAmended;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\ResultVersion;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabException;
use Modules\Laboratory\Support\Progress;

/**
 * The verifier's decisions on submitted results: sign them off, send them
 * back, or reopen a released result for correction.
 */
final class VerifyResults
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Progress $progress,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sign off the results. They are locked, a permanent copy of this
     * version is kept, and the report becomes available to print.
     */
    public function verify(WorkItem $workItem): WorkItem
    {
        Gate::authorize(Permissions::RESULTS_VERIFY, $workItem->branch_id);

        $workItem = DB::connection('clinic')->transaction(function () use ($workItem): WorkItem {
            $item = $this->lock($workItem, WorkStatus::Entered, 'Only results waiting for verification can be verified.');

            if ($this->settings->get(Permissions::SETTING_VERIFIER_MUST_DIFFER, true) && $item->entered_by === Auth::id()) {
                throw new LabException('Results must be verified by a different person from the one who entered them.');
            }

            $results = $item->results()->get();

            if ($results->isEmpty()) {
                throw new LabException('There are no results to verify.');
            }

            $now = now();
            $version = $item->version + 1;
            $reason = $version > 1 ? $item->pending_reason : null;

            ResultVersion::create([
                'work_item_id' => $item->id,
                'version' => $version,
                'snapshot' => $results->map(fn (Result $r): array => [
                    'analyte_id' => $r->analyte_id,
                    'analyte' => $r->analyte_name,
                    'value' => $r->value_text,
                    'value_numeric' => $r->value_numeric === null ? null : (string) $r->value_numeric,
                    'unit' => $r->unit,
                    'range' => $r->ref_text,
                    'flag' => $r->flag->value,
                    'comment' => $r->comment,
                ])->all(),
                'reason' => $reason,
                'entered_by' => $item->entered_by,
                'verified_by' => Auth::id(),
                'verified_at' => $now,
            ]);

            // Verified, then released in the same step: once signed off, the
            // report may be printed.
            $item->forceFill([
                'status' => WorkStatus::Released,
                'verified_by' => Auth::id(),
                'verified_at' => $now,
                'released_at' => $now,
                'version' => $version,
                'pending_reason' => null,
                'lock_version' => $item->lock_version + 1,
            ])->save();

            $this->audit->record($version > 1 ? 'results.amended' : 'results.verified', $item, ['status' => WorkStatus::Entered->value], [
                'status' => WorkStatus::Released->value,
                'version' => $version,
                'test' => $item->test_name,
            ], $reason ? ['reason' => $reason] : []);

            return $item;
        });

        $this->progress->refreshSample($workItem->sample);
        $this->progress->refreshOrder($workItem->order);

        event(new ResultsReleased($workItem));

        if ($workItem->version > 1) {
            event(new ResultsAmended($workItem));
        }

        return $workItem;
    }

    /** Return submitted results to the technician with a reason. */
    public function sendBack(WorkItem $workItem, string $reason): WorkItem
    {
        Gate::authorize(Permissions::RESULTS_VERIFY, $workItem->branch_id);
        $this->requireReason($reason);

        return DB::connection('clinic')->transaction(function () use ($workItem, $reason): WorkItem {
            $item = $this->lock($workItem, WorkStatus::Entered, 'Only results waiting for verification can be sent back.');

            // A result being amended goes back to "being amended"; a first
            // entry goes back to "to enter".
            $back = $item->version > 0 ? WorkStatus::Amending : WorkStatus::Ready;

            $item->forceFill([
                'status' => $back,
                'pending_reason' => $item->version > 0 ? $item->pending_reason : 'Sent back: '.trim($reason),
                'lock_version' => $item->lock_version + 1,
            ])->save();

            $this->audit->record('results.sent_back', $item, ['status' => WorkStatus::Entered->value], ['status' => $back->value], ['reason' => trim($reason)]);

            return $item;
        });
    }

    /**
     * Reopen a released result to correct it. The released version stays on
     * record; the corrected one becomes the next version when it is
     * verified. Until then the report cannot be printed.
     */
    public function startAmendment(WorkItem $workItem, string $reason): WorkItem
    {
        Gate::authorize(Permissions::RESULTS_AMEND, $workItem->branch_id);
        $this->requireReason($reason);

        $workItem = DB::connection('clinic')->transaction(function () use ($workItem, $reason): WorkItem {
            $item = $this->lock($workItem, WorkStatus::Released, 'Only a released result can be amended.');

            $item->forceFill([
                'status' => WorkStatus::Amending,
                'pending_reason' => trim($reason),
                'lock_version' => $item->lock_version + 1,
            ])->save();

            $this->audit->record('results.amendment_started', $item, ['status' => WorkStatus::Released->value], [
                'status' => WorkStatus::Amending->value,
                'version' => $item->version,
            ], ['reason' => trim($reason)]);

            return $item;
        });

        $this->progress->refreshSample($workItem->sample);

        return $workItem;
    }

    private function lock(WorkItem $workItem, WorkStatus $required, string $otherwise): WorkItem
    {
        $item = WorkItem::whereKey($workItem->id)->lockForUpdate()->firstOrFail();

        if ($item->status !== $required) {
            throw new LabException($otherwise);
        }

        return $item;
    }

    private function requireReason(string $reason): void
    {
        if (blank($reason)) {
            throw new LabException('A reason is required.');
        }
    }
}
