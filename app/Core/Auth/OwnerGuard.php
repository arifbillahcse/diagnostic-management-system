<?php

declare(strict_types=1);

namespace App\Core\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Makes sure a clinic can never lose its last active owner.
 */
final class OwnerGuard
{
    /** @return list<int> IDs of active, non-deleted users who hold the owner role in every branch */
    public function activeOwnerIds(): array
    {
        return DB::connection('clinic')->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('roles.key', Role::OWNER)
            ->whereNull('user_roles.branch_id')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->distinct()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Call before deactivating a user or removing their owner role.
     * Lock-for-update on the caller's transaction is not needed at this
     * scale; the check and the change run in one transaction.
     */
    public function assertNotLastOwner(User $user): void
    {
        $owners = $this->activeOwnerIds();

        if ($owners === [$user->id]) {
            throw new LastOwnerException;
        }
    }
}
