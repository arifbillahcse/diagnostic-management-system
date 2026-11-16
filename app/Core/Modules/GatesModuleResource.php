<?php

declare(strict_types=1);

namespace App\Core\Modules;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * For a module's Filament resources: every operation needs the module
 * switched on and the permission that permissionFor() names.
 */
trait GatesModuleResource
{
    use GatesModuleAccess;

    /**
     * The permission for an operation ("viewAny", "view", "create",
     * "update", "delete", ...), or null to refuse it.
     */
    abstract protected static function permissionFor(string $operation): ?string;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $permission = static::permissionFor($action instanceof UnitEnum ? $action->name : $action);

        return static::moduleAllows() && $permission !== null && Gate::allows($permission)
            ? Response::allow()
            : Response::deny();
    }
}
