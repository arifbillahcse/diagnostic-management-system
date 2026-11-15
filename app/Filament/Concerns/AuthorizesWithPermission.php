<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Makes a Filament resource answer every authorization question from one
 * permission key. A resource can narrow it further by overriding allows().
 *
 * This controls what the screen shows. The action classes check the same
 * permission again when they run.
 */
trait AuthorizesWithPermission
{
    abstract public static function permission(): string;

    /** Extra rule per operation ("viewAny", "create", "update", "delete", ...). */
    protected static function allows(string $action, ?Model $record): bool
    {
        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;

        return Gate::allows(static::permission()) && static::allows($action, $record)
            ? Response::allow()
            : Response::deny();
    }
}
