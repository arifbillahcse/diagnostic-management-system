<?php

declare(strict_types=1);

namespace App\Core\Branches;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The branch the signed-in user is working in.
 *
 * Without multi-branch: their default branch, or the clinic's first active
 * branch. With multi-branch on (the multibranch module hands a check to
 * useSwitching()), a user with more than one branch picks one with the
 * switcher, and lists can be limited to the branches the user may see.
 * Core never depends on that module: without the check, switching is off.
 */
final class CurrentBranch
{
    private const SESSION_KEY = 'dms.branch_id';

    private ?Closure $switchingOn = null;

    public function useSwitching(Closure $isOn): void
    {
        $this->switchingOn = $isOn;
    }

    /** Multi-branch is on for this clinic and the user has more than one branch. */
    public function multi(): bool
    {
        return $this->switchingOn !== null && (bool) ($this->switchingOn)() && $this->allowed()->count() > 1;
    }

    /** Multi-branch is on for this clinic, whatever the user's branches. */
    public function switchingOn(): bool
    {
        return $this->switchingOn !== null && (bool) ($this->switchingOn)();
    }

    public function get(): Branch
    {
        if ($this->multi() && ($chosen = session(self::SESSION_KEY)) !== null) {
            $branch = $this->allowed()->firstWhere('id', (int) $chosen);

            if ($branch !== null) {
                return $branch;
            }
        }

        $user = Auth::user();

        $branch = $user instanceof User && $user->default_branch_id !== null
            ? Branch::where('is_active', true)->find($user->default_branch_id)
            : null;

        return $branch
            ?? Branch::where('is_active', true)->orderBy('id')->first()
            ?? throw new RuntimeException('This clinic has no active branch.');
    }

    public function set(Branch $branch): void
    {
        if (! $this->allowed()->contains('id', $branch->id)) {
            throw new RuntimeException('You do not work in that branch.');
        }

        session()->put(self::SESSION_KEY, $branch->id);
    }

    /**
     * The active branches the user works in: every branch for the owner or a
     * role given for all branches; otherwise the branches of their roles.
     *
     * @return Collection<int, Branch>
     */
    public function allowed(): Collection
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return collect();
        }

        return $this->load($user);
    }

    /** Branch ids lists are limited to, or null when lists are not limited (multi-branch off). @return list<int>|null */
    public function scope(): ?array
    {
        return $this->switchingOn() ? $this->allowed()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all() : null;
    }

    /** @return Collection<int, Branch> */
    private function load(User $user): Collection
    {
        $roles = DB::connection('clinic')->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->get(['roles.key', 'user_roles.branch_id']);

        $all = $roles->contains(fn ($r): bool => $r->key === Role::OWNER || $r->branch_id === null);
        $active = Branch::where('is_active', true)->orderBy('name');

        return $all
            ? $active->get()
            : $active->whereIn('id', $roles->pluck('branch_id')->filter()->unique()->all())->get();
    }
}
