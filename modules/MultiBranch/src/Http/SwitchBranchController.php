<?php

declare(strict_types=1);

namespace Modules\MultiBranch\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Branches\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Changes the branch the user is working in, for this session. Only branches they work in. */
final class SwitchBranchController
{
    public function __invoke(Request $request, CurrentBranch $branches, AuditLogger $audit): RedirectResponse
    {
        $id = (int) $request->input('branch_id');
        $branch = $branches->allowed()->firstWhere('id', $id);

        abort_if($branch === null, 403, 'You do not work in that branch.');

        $from = $branches->get();
        $branches->set($branch);

        if ($from->id !== $branch->id) {
            $audit->record('branch.switched', Branch::find($branch->id), ['branch' => $from->name], ['branch' => $branch->name]);
        }

        return redirect()->back(fallback: '/');
    }
}
