<?php

declare(strict_types=1);

namespace App\Core\Branches;

use App\Core\Auth\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The branch the signed-in user is working in: their default branch, or the
 * clinic's first active branch. The multi-branch module will add a switcher.
 */
final class CurrentBranch
{
    public function get(): Branch
    {
        $user = Auth::user();

        $branch = $user instanceof User && $user->default_branch_id !== null
            ? Branch::where('is_active', true)->find($user->default_branch_id)
            : null;

        return $branch
            ?? Branch::where('is_active', true)->orderBy('id')->first()
            ?? throw new RuntimeException('This clinic has no active branch.');
    }
}
