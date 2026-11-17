<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Modules\Entitlements;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Moves a clinic to another plan (a platform task: the clinic cannot do it
 * itself). Modules the new plan does not include are switched off, those
 * that depend on them first. Nothing is deleted: their data stays, and
 * comes back when the plan includes them again. Branches over a lower
 * limit stay as they are; only new ones are refused.
 */
final class ChangePlan
{
    /** @return array{switched_off: list<string>, branches: int, branch_limit: ?int} */
    public function handle(Clinic $clinic, Plan $plan): array
    {
        $from = $clinic->plan?->key;

        DB::connection('control')->transaction(function () use ($clinic, $plan): void {
            $clinic->forceFill(['plan_id' => $plan->id])->save();
            DB::connection('control')->table('provisioning_log')->insert([
                'clinic_id' => $clinic->id, 'step' => 'plan_changed', 'status' => 'done',
                'message' => "Plan changed to {$plan->key}", 'created_at' => now(),
            ]);
        });

        return app(ClinicManager::class)->run($clinic, function () use ($plan, $from): array {
            app(Entitlements::class)->flush();
            $switchedOff = $this->switchOffNotIncluded($plan);

            app(AuditLogger::class)->record('plan.changed', null, ['plan' => $from], ['plan' => $plan->key], [
                'switched_off' => $switchedOff,
            ]);

            return [
                'switched_off' => $switchedOff,
                'branches' => Branch::where('is_active', true)->count(),
                'branch_limit' => $plan->max_branches,
            ];
        });
    }

    /** @return list<string> */
    private function switchOffNotIncluded(Plan $plan): array
    {
        $registry = app(ModuleRegistry::class);
        $state = app(ModuleState::class);
        $state->flush();

        // Not in the plan, plus anything that needs one of those.
        $off = array_values(array_filter(array_keys($registry->all()), fn (string $k): bool => $state->isEnabled($k) && ! $plan->includesModule($k)));
        for ($i = 0; $i < count($off); $i++) {
            foreach ($registry->dependentsOf($off[$i]) as $dependent) {
                if ($state->isEnabled($dependent) && ! in_array($dependent, $off, true)) {
                    $off[] = $dependent;
                }
            }
        }

        // Switch off dependents before what they depend on: reverse load order.
        $order = array_reverse(array_keys($registry->all()));
        $off = array_values(array_filter($order, fn (string $k): bool => in_array($k, $off, true)));

        foreach ($off as $key) {
            app(ModuleManager::class)->disable($key);
        }

        return $off;
    }
}
