<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Inventory\Enums;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\StockReports;

/** Stock that needs attention, on the dashboard. */
class StockAlertsWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Stock';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::STOCK_VIEW;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        $a = app(StockReports::class)->alerts();

        return [
            Stat::make('Stock value', '৳ '.$a['value']->formatted()),
            Stat::make('Low or out of stock', (string) $a['low'])->color($a['low'] > 0 ? 'warning' : 'gray')
                ->url(ItemResource::getUrl('index', ['filters' => ['low' => ['isActive' => true]]])),
            Stat::make('Expiring within '.Enums::EXPIRY_WARNING_DAYS.' days', (string) $a['expiring'])
                ->description($a['expired'] > 0 ? "{$a['expired']} batch(es) already expired" : null)
                ->color($a['expired'] > 0 ? 'danger' : ($a['expiring'] > 0 ? 'warning' : 'gray'))
                ->url(ExpiringStockPage::getUrl()),
            Stat::make('Used without stock', (string) $a['shortfalls'])->color($a['shortfalls'] > 0 ? 'warning' : 'gray')
                ->url(ShortfallResource::getUrl('index')),
        ];
    }
}
