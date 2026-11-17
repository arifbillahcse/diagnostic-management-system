<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Inventory\Enums;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\StockReports;
use UnitEnum;

class ExpiringStockPage extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 36;

    protected static ?string $navigationLabel = 'Expiry dates';

    protected static ?string $title = 'Expiry dates';

    protected static ?string $slug = 'inventory/expiry';

    protected string $view = 'inventory::expiry';

    public string $show = 'soon';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::STOCK_VIEW;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $rows = app(StockReports::class)->batches();

        return array_values(match ($this->show) {
            'expired' => array_filter($rows, fn (array $r): bool => $r['days'] < 0),
            'soon' => array_filter($rows, fn (array $r): bool => $r['days'] <= Enums::EXPIRY_WARNING_DAYS),
            default => $rows,
        });
    }
}
