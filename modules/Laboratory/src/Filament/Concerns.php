<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Laboratory\Support\LabException;

/**
 * Runs a laboratory action from a screen and turns a refusal into a
 * notification the user can read.
 */
final class Concerns
{
    public static function attempt(Closure $action, string $success, string $failure = 'Not done'): bool
    {
        try {
            $action();
        } catch (LabException $e) {
            Notification::make()->danger()->title($failure)->body($e->getMessage())->persistent()->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title($failure)->body('You do not have permission to do this.')->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }
}
