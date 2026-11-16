<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament;

use App\Core\Branches\CurrentBranch;
use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\DailyFigures;

/** Today's front desk figures for the user's branch. */
class TodayWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = -10;

    protected ?string $heading = 'Today';

    public static function moduleKey(): string
    {
        return 'frontdesk';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::ORDERS_VIEW;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        $figures = app(DailyFigures::class);
        $today = $figures->for($figures->today(), app(CurrentBranch::class)->get()->id);

        return [
            Stat::make('Patients', (string) $today['patients_count'])->description($today['orders_count'].' order(s)'),
            Stat::make('Billed', '৳ '.$today['billed_total']->formatted()),
            Stat::make('Collected', '৳ '.$today['collected_total']->formatted())
                ->description($today['refunded_total']->isZero() ? null : 'Refunded ৳ '.$today['refunded_total']->formatted()),
            Stat::make('Due from today', '৳ '.$today['due_total']->formatted())
                ->color($today['due_total']->isZero() ? 'success' : 'danger'),
        ];
    }
}
