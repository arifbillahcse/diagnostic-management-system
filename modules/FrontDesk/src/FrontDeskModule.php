<?php

declare(strict_types=1);

namespace Modules\FrontDesk;

use App\Core\Modules\Module;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Console\CheckInvoicesCommand;
use Modules\FrontDesk\Console\SummarizeCommand;

final class FrontDeskModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'receptionist' => [
                Permissions::ORDERS_VIEW,
                Permissions::ORDERS_CREATE,
                Permissions::ORDERS_CANCEL,
                Permissions::DISCOUNTS_APPLY,
                Permissions::PAYMENTS_RECORD,
                Permissions::REFUNDS_REQUEST,
                Permissions::CASH_MANAGE,
            ],
            'accountant' => [
                Permissions::ORDERS_VIEW,
                Permissions::ORDERS_CANCEL,
                Permissions::ORDERS_ADJUST,
                Permissions::DISCOUNTS_APPLY,
                Permissions::PAYMENTS_RECORD,
                Permissions::REFUNDS_REQUEST,
                Permissions::REFUNDS_APPROVE,
                Permissions::CASH_MANAGE,
                Permissions::CASH_VIEW_ALL,
            ],
        ];
    }

    public function defaultLimits(): array
    {
        // Starting values. The right figures come from each clinic.
        return [
            'receptionist' => [Permissions::LIMIT_DISCOUNT_PERCENT => '10'],
            'accountant' => [Permissions::LIMIT_DISCOUNT_PERCENT => '10'],
        ];
    }

    public function resources(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::resources() : [];
    }

    public function pages(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::pages() : [];
    }

    public function widgets(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::widgets() : [];
    }

    public function boot(): void
    {
        View::addNamespace('frontdesk', $this->manifest->path.'/resources/views');

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([
            SummarizeCommand::class,
            CheckInvoicesCommand::class,
        ]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            // Shortly after midnight in Bangladesh: closes yesterday's figures.
            $schedule->command('frontdesk:summarize')->dailyAt('00:20')->timezone('Asia/Dhaka');
            $schedule->command('frontdesk:check-invoices')->dailyAt('01:00')->timezone('Asia/Dhaka');
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:frontdesk'])->group($routes);
        }
    }
}
