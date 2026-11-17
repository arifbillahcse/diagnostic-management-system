<?php

declare(strict_types=1);

namespace Modules\People\Support;

use App\Core\Branches\Branch;
use App\Core\Branches\CurrentBranch;
use Illuminate\Support\Facades\Gate;

/**
 * Branch pickers of this module: only branches where the user holds the
 * permission, starting on the branch chosen in the panel's switcher.
 */
final class Branches
{
    /** @return array<int, string> */
    public static function options(string $permission): array
    {
        return Branch::orderBy('name')->get()
            ->filter(fn (Branch $b): bool => Gate::allows($permission, $b->id))
            ->pluck('name', 'id')->all();
    }

    public static function default(string $permission): ?int
    {
        $options = self::options($permission);
        $current = app(CurrentBranch::class)->get()->id;

        return array_key_exists($current, $options) ? $current : (array_key_first($options) ?? null);
    }
}
