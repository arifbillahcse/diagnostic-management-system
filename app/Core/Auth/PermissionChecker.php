<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Modules\ModuleState;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do this, in this branch?".
 *
 * Order of checks:
 *   1. The permission must exist.
 *   2. Its module must be enabled for the clinic (core always is).
 *   3. The user must be active.
 *   4. One of the user's roles must grant it, for all branches or for the
 *      branch asked about. The owner role grants everything.
 */
final class PermissionChecker
{
    /** @var array<int, list<array{branch: ?int, owner: bool, permissions: array<string, true>}>> */
    private array $grants = [];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly ModuleState $modules,
    ) {}

    public function allows(User $user, string $permission, ?int $branchId = null): bool
    {
        $module = $this->registry->moduleOf($permission);

        if ($module === null) {
            return false;
        }

        if ($module !== 'core' && ! $this->modules->isEnabled($module)) {
            return false;
        }

        if (! $user->is_active) {
            return false;
        }

        foreach ($this->grantsFor($user) as $grant) {
            $coversBranch = $branchId === null || $grant['branch'] === null || $grant['branch'] === $branchId;

            if ($coversBranch && ($grant['owner'] || isset($grant['permissions'][$permission]))) {
                return true;
            }
        }

        return false;
    }

    /** Forget what has been loaded. Called when roles, grants, or the clinic change. */
    public function flush(): void
    {
        $this->grants = [];
    }

    /** @return list<array{branch: ?int, owner: bool, permissions: array<string, true>}> */
    private function grantsFor(User $user): array
    {
        if (isset($this->grants[$user->id])) {
            return $this->grants[$user->id];
        }

        $db = DB::connection('clinic');

        $assignments = $db->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->get(['roles.id', 'roles.key', 'user_roles.branch_id']);

        $permissionsByRole = $db->table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $assignments->pluck('id')->unique()->all())
            ->get(['role_permissions.role_id', 'permissions.key'])
            ->groupBy('role_id');

        $grants = [];

        foreach ($assignments as $assignment) {
            $grants[] = [
                'branch' => $assignment->branch_id === null ? null : (int) $assignment->branch_id,
                'owner' => $assignment->key === Role::OWNER,
                'permissions' => array_fill_keys(
                    ($permissionsByRole[$assignment->id] ?? collect())->pluck('key')->all(),
                    true,
                ),
            ];
        }

        return $this->grants[$user->id] = $grants;
    }
}
