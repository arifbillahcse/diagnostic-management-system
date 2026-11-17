<?php

declare(strict_types=1);

namespace Modules\Inventory;

use App\Core\Modules\Module;
use App\Core\Numbering\NumberGenerator;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Inventory\Console\CheckCommand;
use Modules\Inventory\Listeners\UseStockForOrders;

final class InventoryModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'accountant' => [Permissions::STOCK_VIEW, Permissions::PURCHASES_RECORD, Permissions::PURCHASES_PAY],
            'lab_technician' => [Permissions::STOCK_VIEW],
            'verifier' => [Permissions::STOCK_VIEW],
        ];
    }

    public function appendOnlyTables(): array
    {
        return ['inv_purchase_lines', 'inv_movements'];
    }

    public function exports(): array
    {
        return [
            'inv_items' => 'Stock items',
            'inv_suppliers' => 'Suppliers',
            'inv_purchases' => 'Purchases',
            'inv_purchase_lines' => 'Purchase lines',
            'inv_movements' => 'Stock movements',
            'inv_usages' => 'Stock use per test',
            'inv_shortfalls' => 'Stock used without stock on hand',
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
        View::addNamespace('inventory', $this->manifest->path.'/resources/views');

        NumberGenerator::registerDefault('purchase', 'PO-{yymm}-{00001}', 'Purchase number', 'inventory');

        foreach ([OrderPlaced::class, OrderItemAdded::class, OrderItemCancelled::class, OrderCancelled::class] as $event) {
            Event::listen($event, [UseStockForOrders::class, 'handle']);
        }

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([CheckCommand::class]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inventory:check')->dailyAt('01:20')->timezone(config('dms.clinics.default_timezone'));
        });
    }
}
