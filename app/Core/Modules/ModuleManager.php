<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\ClinicMigrator;
use Illuminate\Support\Facades\DB;

/**
 * Switches modules on and off for the current clinic.
 *
 * This is the engine. Permission checks for a user doing this from the
 * screen live in the EnableModule and DisableModule actions.
 */
final class ModuleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleState $state,
        private readonly Entitlements $entitlements,
        private readonly ClinicMigrator $migrator,
        private readonly PermissionRegistry $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public function enable(string $key): void
    {
        $manifest = $this->registry->get($key) ?? throw ModuleException::unknown($key);

        if ($this->state->isEnabled($key)) {
            return;
        }

        if (! $this->entitlements->allows($key)) {
            throw ModuleException::notEntitled($manifest->name);
        }

        $missing = array_filter($manifest->requires, fn (string $required): bool => ! $this->state->isEnabled($required));

        if ($missing !== []) {
            throw ModuleException::missingRequirements($manifest->name, $this->names($missing));
        }

        $firstInstall = ! $this->state->isInstalled($key);

        // Table changes cannot be part of a MySQL transaction, so they run
        // first. Migrations are safe to repeat if a later step fails.
        $this->migrator->runPath($manifest->migrationsPath());

        DB::connection('clinic')->transaction(function () use ($manifest, $key, $firstInstall): void {
            $now = now();

            DB::connection('clinic')->table('modules')->upsert(
                [[
                    'module_key' => $key,
                    'version' => $manifest->version,
                    'status' => ModuleState::ENABLED,
                    'installed_at' => $now,
                    'enabled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['module_key'],
                ['version', 'status', 'enabled_at', 'updated_at'],
            );

            $this->state->flush();
            $this->permissions->sync();

            if ($firstInstall) {
                $this->permissions->applyDefaultGrants($this->registry->module($key)->defaultGrants());
            }

            $this->audit->record('module.enabled', null, [], [], [
                'module' => $key,
                'version' => $manifest->version,
                'first_install' => $firstInstall,
            ]);
        });

        $this->state->flush();
        event(new ModuleEnabled($key));
    }

    public function disable(string $key): void
    {
        $manifest = $this->registry->get($key) ?? throw ModuleException::unknown($key);

        if (! $this->state->isEnabled($key)) {
            return;
        }

        $dependents = array_filter($this->registry->dependentsOf($key), fn (string $other): bool => $this->state->isEnabled($other));

        if ($dependents !== []) {
            throw ModuleException::hasDependents($manifest->name, $this->names($dependents));
        }

        // Only the status changes. The module's tables and rows stay.
        DB::connection('clinic')->transaction(function () use ($key): void {
            DB::connection('clinic')->table('modules')->where('module_key', $key)->update([
                'status' => ModuleState::DISABLED,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('module.disabled', null, [], [], ['module' => $key]);
        });

        $this->state->flush();
        event(new ModuleDisabled($key));
    }

    /**
     * Everything the module manager screen needs, in load order.
     *
     * @return list<array{manifest: ModuleManifest, status: string, entitled: bool, missing: list<string>, dependents: list<string>}>
     */
    public function overview(): array
    {
        $rows = [];

        foreach ($this->registry->all() as $key => $manifest) {
            $rows[] = [
                'manifest' => $manifest,
                'status' => $this->state->isEnabled($key)
                    ? ModuleState::ENABLED
                    : ($this->state->isInstalled($key) ? ModuleState::DISABLED : 'not_installed'),
                'entitled' => $this->entitlements->allows($key),
                'missing' => $this->names(array_filter($manifest->requires, fn (string $r): bool => ! $this->state->isEnabled($r))),
                'dependents' => $this->names(array_filter($this->registry->dependentsOf($key), fn (string $d): bool => $this->state->isEnabled($d))),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string>  $keys
     * @return list<string>
     */
    private function names(array $keys): array
    {
        return array_values(array_map(fn (string $key): string => $this->registry->get($key)?->name ?? $key, $keys));
    }
}
