<?php

declare(strict_types=1);

namespace App\Core\Auth\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\OwnerGuard;
use App\Core\Auth\PermissionChecker;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates or updates a staff member and their roles.
 *
 * Accepted data: name, email, phone, password (optional on update),
 * is_active, default_branch_id, role_ids (roles held in every branch).
 */
final class SaveUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OwnerGuard $owners,
        private readonly PermissionChecker $checker,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        Gate::authorize(CorePermissions::USERS_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($data): User {
            $user = User::create($this->attributes($data));
            $roleIds = $this->roleIds($data);
            $this->syncRoles($user, $roleIds);

            $this->audit->record('user.created', $user, [], [
                ...$user->only(['name', 'email', 'phone', 'is_active', 'default_branch_id']),
                'roles' => $this->roleNames($roleIds),
            ]);

            $this->checker->flush();

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        Gate::authorize(CorePermissions::USERS_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($user, $data): User {
            $fields = ['name', 'email', 'phone', 'is_active', 'default_branch_id'];
            $oldRoleIds = $this->currentRoleIds($user);
            $old = [...$user->only($fields), 'roles' => $this->roleNames($oldRoleIds)];

            $attributes = $this->attributes($data);
            $newRoleIds = array_key_exists('role_ids', $data) ? $this->roleIds($data) : $oldRoleIds;

            $ownerRoleId = (int) Role::where('key', Role::OWNER)->value('id');
            $losesOwnership = in_array($ownerRoleId, $oldRoleIds, true) && ! in_array($ownerRoleId, $newRoleIds, true);
            $deactivated = $user->is_active && ($attributes['is_active'] ?? true) === false;

            if ($losesOwnership || $deactivated) {
                $this->owners->assertNotLastOwner($user);
            }

            $passwordChanged = isset($attributes['password']);
            $user->update($attributes);
            $this->syncRoles($user, $newRoleIds);

            $new = [...$user->only($fields), 'roles' => $this->roleNames($newRoleIds)];
            [$before, $after] = $this->audit->diff($old, $new);

            if ($passwordChanged) {
                $before['password'] = $after['password'] = 'changed';
            }

            if ($after !== []) {
                $this->audit->record('user.updated', $user, $before, $after);
            }

            $this->checker->flush();

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'is_active', 'default_branch_id']));

        if (isset($attributes['email'])) {
            $attributes['email'] = strtolower(trim((string) $attributes['email']));
        }

        if (filled($data['password'] ?? null)) {
            $attributes['password'] = $data['password'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function roleIds(array $data): array
    {
        $ids = array_map('intval', (array) ($data['role_ids'] ?? []));

        return Role::whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function currentRoleIds(User $user): array
    {
        return DB::connection('clinic')->table('user_roles')
            ->where('user_id', $user->id)
            ->whereNull('branch_id')
            ->orderBy('role_id')
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Replace the user's all-branch roles. Branch-specific assignments are
     * left alone; they are managed by the multi-branch module.
     *
     * @param  list<int>  $roleIds
     */
    private function syncRoles(User $user, array $roleIds): void
    {
        $table = DB::connection('clinic')->table('user_roles');
        $now = now();

        (clone $table)->where('user_id', $user->id)->whereNull('branch_id')->delete();

        foreach ($roleIds as $roleId) {
            (clone $table)->insert([
                'user_id' => $user->id,
                'role_id' => $roleId,
                'branch_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $user->unsetRelation('roles');
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<string>
     */
    private function roleNames(array $roleIds): array
    {
        return Role::whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all();
    }
}
