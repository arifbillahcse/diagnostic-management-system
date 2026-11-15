<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Whether the current clinic's plan and add-ons allow a module.
 * This is decided by the platform owner, in the control database.
 */
final class Entitlements
{
    /** @var array<string, true>|null */
    private ?array $allowed = null;

    public function __construct(private readonly ClinicContext $clinics) {}

    public function allows(string $key): bool
    {
        $allowed = $this->allowed ??= $this->load();

        return isset($allowed[Plan::ALL_MODULES]) || isset($allowed[$key]);
    }

    public function flush(): void
    {
        $this->allowed = null;
    }

    /** @return array<string, true> */
    private function load(): array
    {
        $clinic = $this->clinics->get();

        if ($clinic === null) {
            return [];
        }

        $control = DB::connection('control');

        $fromPlan = $clinic->plan_id === null ? [] : $control->table('plan_modules')
            ->join('plans', 'plans.id', '=', 'plan_modules.plan_id')
            ->where('plans.id', $clinic->plan_id)
            ->where('plans.is_active', true)
            ->pluck('module_key')
            ->all();

        $extras = $control->table('clinic_modules')
            ->where('clinic_id', $clinic->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('module_key')
            ->all();

        return array_fill_keys([...$fromPlan, ...$extras], true);
    }
}
