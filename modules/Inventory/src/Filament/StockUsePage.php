<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleAccess;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\StockReports;
use Throwable;
use UnitEnum;

class StockUsePage extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Stock use report';

    protected static ?string $title = 'Stock use report';

    protected static ?string $slug = 'inventory/use-report';

    protected string $view = 'inventory::use-report';

    public string $from = '';

    public string $to = '';

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

    public function mount(): void
    {
        $today = CarbonImmutable::now(app(ClinicContext::class)->timezone());
        $this->from = $today->startOfMonth()->toDateString();
        $this->to = $today->toDateString();
    }

    /** @return array{0: string, 1: string}|null */
    private function period(): ?array
    {
        try {
            $from = CarbonImmutable::parse($this->from);
            $to = CarbonImmutable::parse($this->to);
        } catch (Throwable) {
            return null;
        }

        return $from->greaterThan($to) || $from->diffInDays($to) > 366 ? null : [$from->toDateString(), $to->toDateString()];
    }

    /** @return list<array<string, mixed>>|null null when the dates are not usable */
    public function getByTestProperty(): ?array
    {
        $period = $this->period();

        return $period === null ? null : app(StockReports::class)->useByTest(...$period);
    }

    /** @return list<array<string, mixed>>|null */
    public function getByItemProperty(): ?array
    {
        $period = $this->period();

        return $period === null ? null : app(StockReports::class)->outByItem(...$period);
    }
}
