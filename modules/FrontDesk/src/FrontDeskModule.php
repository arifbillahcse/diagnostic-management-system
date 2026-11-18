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

    public function appendOnlyTables(): array
    {
        return ['fd_invoice_lines', 'fd_payments'];
    }

    public function exports(): array
    {
        return [
            'fd_service_categories' => 'Service categories',
            'fd_services' => 'Tests and services',
            'fd_package_items' => 'Package contents',
            'fd_service_addons' => 'Supplies charged with a service',
            'fd_referral_doctors' => 'Referral doctors',
            'fd_orders' => 'Orders',
            'fd_order_items' => 'Order items',
            'fd_invoices' => 'Invoices',
            'fd_invoice_lines' => 'Invoice lines',
            'fd_payments' => 'Payments',
            'fd_refunds' => 'Refunds',
            'fd_cash_sessions' => 'Cash sessions',
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

        // Other modules add what can be printed after a bill is saved.
        app()->singleton(Support\PrintLinks::class);
        app()->singleton(Support\OrderReports::class);
        app()->singleton(Support\InvoiceNotes::class);

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([
            SummarizeCommand::class,
            CheckInvoicesCommand::class,
            Console\ImportCommand::class,
        ]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            // Shortly after midnight in Bangladesh: closes yesterday's figures.
            $schedule->command('frontdesk:summarize')->dailyAt('00:20')->timezone(config('dms.clinics.default_timezone'));
            $schedule->command('frontdesk:check-invoices')->dailyAt('01:00')->timezone(config('dms.clinics.default_timezone'));
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:frontdesk'])->group($routes);
        }
    }
}
