<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Imaging\Support\ImagingException;

/**
 * Runs an imaging action from a screen. A refusal becomes a notification
 * and keeps the pop-up open so the user can correct the form.
 */
final class Guard
{
    public static function run(?Action $action, Closure $work, string $success): mixed
    {
        try {
            $result = $work();
        } catch (ImagingException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            $action?->halt();

            return null;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Not done')->body('You do not have permission to do this.')->send();
            $action?->halt();

            return null;
        }

        Notification::make()->success()->title($success)->send();

        return $result;
    }
}
