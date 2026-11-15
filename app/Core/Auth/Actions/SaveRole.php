<?php

declare(strict_types=1);

namespace App\Core\Auth\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\Permission;
use App\Core\Auth\PermissionChecker;
use App\Core\Auth\Role;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates, updates, or deletes a role and its permissions.
 */
final class SaveRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionChecker $checker,
    ) {}

    /** @param list<string> $permissionKeys */
    public function create(string $name, array $permissionKeys): Role
    {
        Gate::authorize(CorePermissions::ROLES_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($name, $permissionKeys): Role {
            $role = Role::create(['key' => $this->uniqueKey($name), 'name' => $name, 'is_system' => false]);
            $keys = $this->sync($role, $permissionKeys);

            $this->audit->record('role.created', $role, [], ['name' => $name, 'permissions' => $keys]);
            $this->checker->flush();

            return $role;
        });
    }

    /** @param list<string> $permissionKeys */
    public function update(Role $role, string $name, array $permissionKeys): Role
    {
        Gate::authorize(CorePermissions::ROLES_MANAGE);
        $this->assertEditable($role);

        return DB::connection('clinic')->transaction(function () use ($role, $name, $permissionKeys): Role {
            $old = ['name' => $role->name, 'permissions' => $role->permissions()->orderBy('key')->pluck('key')->all()];

            $role->update(['name' => $name]);
            $keys = $this->sync($role, $permissionKeys);

            [$before, $after] = $this->audit->diff($old, ['name' => $name, 'permissions' => $keys]);

            if ($after !== []) {
                $this->audit->record('role.updated', $role, $before, $after);
            }

            $this->checker->flush();

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        Gate::authorize(CorePermissions::ROLES_MANAGE);
        $this->assertEditable($role);

        if (DB::connection('clinic')->table('user_roles')->where('role_id', $role->id)->exists()) {
            throw new DomainException('This role is still assigned to users. Remove it from them first.');
        }

        DB::connection('clinic')->transaction(function () use ($role): void {
            $this->audit->record('role.deleted', $role, ['name' => $role->name, 'key' => $role->key], []);
            $role->delete();
            $this->checker->flush();
        });
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_system) {
            throw new DomainException("The {$role->name} role is built in and cannot be changed.");
        }
    }

    /**
     * @param  list<string>  $permissionKeys
     * @return list<string> the keys that were actually stored
     */
    private function sync(Role $role, array $permissionKeys): array
    {
        $permissions = Permission::whereIn('key', $permissionKeys)->orderBy('key')->get(['id', 'key']);
        $role->permissions()->sync($permissions->pluck('id')->all());

        return $permissions->pluck('key')->all();
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'role', 50, '');
        $key = $base;

        for ($i = 2; Role::where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }
}
