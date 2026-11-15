<?php

declare(strict_types=1);

namespace App\Core\Settings\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Changes clinic settings, with a permission check and one audit entry.
 */
final class UpdateSettings
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  "group.key" => value
     * @param  list<string>  $encrypted  names to store encrypted; their values never reach the audit trail
     */
    public function handle(array $values, array $encrypted = []): void
    {
        Gate::authorize(CorePermissions::SETTINGS_MANAGE);

        DB::connection('clinic')->transaction(function () use ($values, $encrypted): void {
            $old = array_intersect_key($this->settings->all(), $values);

            foreach ($values as $name => $value) {
                $this->settings->set($name, $value, in_array($name, $encrypted, true));
            }

            [$before, $after] = $this->audit->diff($old, $values);

            foreach ($encrypted as $name) {
                if (array_key_exists($name, $after)) {
                    $before[$name] = $after[$name] = 'changed';
                }
            }

            if ($after !== []) {
                $this->audit->record('settings.updated', null, $before, $after);
            }
        });
    }
}
