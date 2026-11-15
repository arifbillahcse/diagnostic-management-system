<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use Illuminate\Support\Facades\DB;

/**
 * Knows every permission the code defines, from core and from modules, and
 * keeps the clinic's permissions table in step with it.
 */
final class PermissionRegistry
{
    /** @var array<string, array{module: string, description: string}>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleState $state,
    ) {}

    /** @return array<string, array{module: string, description: string}> */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach (CorePermissions::all() as $key => $description) {
            $definitions[$key] = ['module' => 'core', 'description' => $description];
        }

        foreach (array_keys($this->modules->all()) as $moduleKey) {
            foreach ($this->modules->module($moduleKey)->permissions() as $key => $description) {
                $definitions[$key] = ['module' => $moduleKey, 'description' => $description];
            }
        }

        return $this->definitions = $definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function moduleOf(string $key): ?string
    {
        return $this->definitions()[$key]['module'] ?? null;
    }

    /**
     * Write the permissions of core and of every installed module into the
     * current clinic's database. Existing rows and role grants are kept.
     */
    public function sync(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->definitions() as $key => $definition) {
            if ($definition['module'] === 'core' || $this->state->isInstalled($definition['module'])) {
                $rows[] = [
                    'key' => $key,
                    'module_key' => $definition['module'],
                    'description' => $definition['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::connection('clinic')->table('permissions')->upsert($rows, ['key'], ['module_key', 'description', 'updated_at']);
    }

    /**
     * Give roles a set of permissions, adding to what they already have.
     * Roles the clinic has deleted are skipped.
     *
     * @param  array<string, list<string>>  $grants  role key => permission keys
     */
    public function applyDefaultGrants(array $grants): void
    {
        foreach ($grants as $roleKey => $permissionKeys) {
            $role = Role::where('key', $roleKey)->first();

            if ($role !== null && $permissionKeys !== []) {
                $role->permissions()->syncWithoutDetaching(Permission::whereIn('key', $permissionKeys)->pluck('id')->all());
            }
        }

        app(PermissionChecker::class)->flush();
    }
}
