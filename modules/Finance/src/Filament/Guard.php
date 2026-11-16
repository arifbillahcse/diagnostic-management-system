<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Finance\Support\FinanceException;

/**
 * Runs a finance action from a screen. A refusal becomes a notification
 * and keeps the pop-up open so the user can correct the form.
 */
final class Guard
{
    public static function run(Action $action, Closure $work, string $success): mixed
    {
        try {
            $result = $work();
        } catch (FinanceException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            $action->halt();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Not done')->body('You do not have permission to do this.')->send();
            $action->halt();
        }

        Notification::make()->success()->title($success)->send();

        return $result;
    }

    public static function amount(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('৳')->required()
            ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter an amount such as 500 or 499.50.']);
    }
}
