<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\FrontDesk\Support\FrontDeskException;

/**
 * Runs a front desk action from a screen and turns a refusal into a
 * notification the user can read.
 */
trait RunsFrontDeskActions
{
    /** @return bool whether the action went through */
    protected function attempt(Closure $action, string $success, string $failure = 'Not done'): bool
    {
        try {
            $action();
        } catch (FrontDeskException $e) {
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
