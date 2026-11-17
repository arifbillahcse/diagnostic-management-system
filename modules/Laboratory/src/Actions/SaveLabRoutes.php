<?php

declare(strict_types=1);

namespace Modules\Laboratory\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabException;

/**
 * Sets which branch tests the samples of each branch. A branch set to
 * itself (or left empty) has its own laboratory. Only new samples follow
 * a change; samples already made keep their laboratory.
 */
final class SaveLabRoutes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<int|string, int|string|null> $routes branch id => branch id that tests its samples */
    public function handle(array $routes): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $branches = Branch::pluck('name', 'id');
        $clean = [];

        foreach ($routes as $from => $to) {
            $from = (int) $from;
            $to = blank($to) ? $from : (int) $to;

            if (! $branches->has($from) || ! $branches->has($to)) {
                throw new LabException('Choose branches from the list.');
            }
            $clean[$from] = $to;
        }

        foreach ($clean as $from => $to) {
            // A laboratory must test its own samples: no chains such as A → B → C.
            if ($to !== $from && ($clean[$to] ?? $to) !== $to) {
                throw new LabException("{$branches[$to]} sends its own samples away, so it cannot test {$branches[$from]}'s samples.");
            }
        }

        DB::connection('clinic')->transaction(function () use ($clean, $branches): void {
            $db = DB::connection('clinic')->table('lab_routes');
            $before = $db->pluck('lab_branch_id', 'branch_id')->map(fn ($id) => $branches[$id] ?? $id)->all();
            $after = [];

            foreach ($clean as $from => $to) {
                if ($to === $from) {
                    DB::connection('clinic')->table('lab_routes')->where('branch_id', $from)->delete();

                    continue;
                }

                DB::connection('clinic')->table('lab_routes')->updateOrInsert(['branch_id' => $from], ['lab_branch_id' => $to, 'updated_at' => now(), 'created_at' => now()]);
                $after[$from] = $branches[$to];
            }

            if ($before !== $after) {
                $this->audit->record('lab.routes_changed', null, ['tested_at' => $before], ['tested_at' => $after]);
            }
        });
    }
}
