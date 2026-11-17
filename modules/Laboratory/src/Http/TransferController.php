<?php

declare(strict_types=1);

namespace Modules\Laboratory\Http;

use App\Core\Documents\Letterhead;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Models\Transfer;
use Modules\Laboratory\Permissions;

/** The courier list that goes in the box with the samples, to tick off at the laboratory. */
final class TransferController
{
    public function __invoke(Transfer $transfer, Letterhead $letterhead, ClinicContext $clinics): View
    {
        if (! Gate::allows(Permissions::WORK_VIEW, $transfer->from_branch_id)) {
            Gate::authorize(Permissions::WORK_VIEW, $transfer->to_branch_id);
        }

        return view('laboratory::transfer', [
            'transfer' => $transfer->load(['fromBranch', 'toBranch', 'sender', 'samples.patient', 'samples.sampleType', 'samples.workItems']),
            'clinic' => $letterhead->data($transfer->fromBranch),
            'tz' => $clinics->timezone(),
        ]);
    }
}
