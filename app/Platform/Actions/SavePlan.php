<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Core\Modules\ModuleRegistry;
use App\Core\Money\Money;
use App\Core\Tenancy\Plan;
use App\Platform\PlatformAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Edits a plan's name, prices, branch limit and modules. Clinics on it follow at once. */
final class SavePlan
{
    /** @param array{name: string, description?: ?string, price_monthly: string, price_yearly: string, max_branches?: ?int, modules: list<string>, is_active: bool} $data */
    public function handle(Plan $plan, array $data): Plan
    {
        if (! Auth::guard('platform')->check()) {
            throw new RuntimeException('Only a signed-in platform user can do this.');
        }

        $modules = array_values(array_unique($data['modules']));
        $unknown = array_diff($modules, [Plan::ALL_MODULES, ...array_keys(app(ModuleRegistry::class)->all())]);

        if ($modules === [] || $unknown !== []) {
            throw new RuntimeException($modules === [] ? 'Choose at least one module.' : 'Unknown module(s): '.implode(', ', $unknown));
        }

        $before = ['name' => $plan->name, 'monthly' => $plan->price_monthly->toDecimal(), 'yearly' => $plan->price_yearly->toDecimal(), 'branches' => $plan->max_branches, 'modules' => $plan->moduleKeys(), 'on_sale' => $plan->is_active];

        DB::connection('control')->transaction(function () use ($plan, $data, $modules): void {
            $plan->forceFill([
                'name' => trim($data['name']),
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'price_monthly' => Money::fromDecimal($data['price_monthly']),
                'price_yearly' => Money::fromDecimal($data['price_yearly']),
                'max_branches' => blank($data['max_branches'] ?? null) ? null : max(1, (int) $data['max_branches']),
                'is_active' => $data['is_active'],
            ])->save();
            $plan->setModuleKeys($modules);
        });

        $plan->refresh();
        PlatformAudit::record('plan.saved', null, ['plan' => $plan->key, 'before' => $before, 'after' => [
            'name' => $plan->name, 'monthly' => $plan->price_monthly->toDecimal(), 'yearly' => $plan->price_yearly->toDecimal(), 'branches' => $plan->max_branches, 'modules' => $plan->moduleKeys(), 'on_sale' => $plan->is_active,
        ]]);

        return $plan;
    }
}
