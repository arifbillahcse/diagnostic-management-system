<?php

declare(strict_types=1);

namespace App\Core\Modules;

use Illuminate\Support\Facades\Gate;

/**
 * For Filament resources, pages, and widgets that belong to a module.
 *
 * All clinics share one code base, so a module's screens are always
 * registered. This trait is what hides and blocks them for a clinic that
 * has the module disabled, or a user without the permission.
 */
trait GatesModuleAccess
{
    /** The key of the module this screen belongs to. */
    abstract public static function moduleKey(): string;

    /** The permission needed to open this screen, or null for any signed-in user. */
    public static function permissionKey(): ?string
    {
        return null;
    }

    public static function moduleAllows(): bool
    {
        if (! app(ModuleState::class)->isEnabled(static::moduleKey())) {
            return false;
        }

        $permission = static::permissionKey();

        return $permission === null || Gate::allows($permission);
    }
}
