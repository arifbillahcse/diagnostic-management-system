<?php

declare(strict_types=1);

namespace Modules\Owner\Filament;

use App\Core\Modules\GatesModuleAccess;
use App\Core\Modules\ModuleState;
use App\Core\Money\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Finance\Support\ProfitReport;
use Modules\Owner\Permissions;
use Modules\Owner\Support\SalesReports;

/** The month so far, for the owner. */
class MonthWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = -9;

    protected ?string $heading = 'This month so far';

    public static function moduleKey(): string
    {
        return 'owner';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::DASHBOARD_VIEW;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        $reports = app(SalesReports::class);
        [$from, $to] = $reports->thisMonth();
        $days = $reports->byDay($from, $to);

        $sum = fn (string $key): Money => Money::sum(...array_column($days, $key));
        $patients = array_sum(array_column($days, 'patients'));
        $collected = $sum('collected');
        $refunded = $sum('refunded');

        $stats = [
            Stat::make('Patient visits', number_format($patients)),
            Stat::make('Billed', '৳ '.$sum('billed')->formatted()),
            Stat::make('Collected', '৳ '.$collected->formatted())
                ->description($refunded->isZero() ? null : 'Refunded ৳ '.$refunded->formatted()),
        ];

        // With finance switched on, show what is left after costs.
        if (app(ModuleState::class)->isEnabled('finance')) {
            $profit = app(ProfitReport::class)->for($from, $to);
            $stats[] = Stat::make('Left after expenses', '৳ '.$profit['net']->formatted())
                ->description('Expenses ৳ '.$profit['expenses']->formatted())
                ->color($profit['net']->isNegative() ? 'danger' : 'success');
        }

        return $stats;
    }
}
