<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\DB;

/**
 * Which modules the current clinic has installed and enabled.
 * Read once per clinic and kept in memory until something changes.
 */
final class ModuleState
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    /** @var array<string, string>|null module key => status */
    private ?array $statuses = null;

    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly ModuleRegistry $registry,
    ) {}

    /** @return array<string, string> module key => status, for installed modules */
    public function statuses(): array
    {
        if (! $this->clinics->has()) {
            return [];
        }

        return $this->statuses ??= DB::connection('clinic')->table('modules')->pluck('status', 'module_key')->all();
    }

    public function isInstalled(string $key): bool
    {
        return isset($this->statuses()[$key]);
    }

    /**
     * Enabled for this clinic, and still present in the code base.
     * Entitlement is checked when a module is switched on, and again by the
     * subscription layer when a plan changes.
     */
    public function isEnabled(string $key): bool
    {
        return ($this->statuses()[$key] ?? null) === self::ENABLED && $this->registry->has($key);
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_values(array_filter(array_keys($this->statuses()), fn (string $key): bool => $this->isEnabled($key)));
    }

    public function flush(): void
    {
        $this->statuses = null;
    }
}
