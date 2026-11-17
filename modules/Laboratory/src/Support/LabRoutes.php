<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which branch's laboratory tests the samples taken at a branch. A branch
 * with no route tests its own samples.
 */
final class LabRoutes
{
    public function labFor(int $branchId): int
    {
        $to = DB::connection('clinic')->table('lab_routes')->where('branch_id', $branchId)->value('lab_branch_id');

        return $to === null ? $branchId : (int) $to;
    }
}
