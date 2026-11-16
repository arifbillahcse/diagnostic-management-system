<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament;

use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\FrontDesk\Support\DailyFigures;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;

/** Where the laboratory's work stands right now. */
class LabStatusWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = -8;

    protected ?string $heading = 'Laboratory';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::WORK_VIEW;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        $count = fn (WorkStatus ...$statuses): int => WorkItem::whereIn('status', array_map(fn (WorkStatus $s): string => $s->value, $statuses))->count();
        $today = app(DailyFigures::class)->today()->utc();

        return [
            Stat::make('Samples to collect', (string) Sample::whereIn('status', [SampleStatus::Pending->value, SampleStatus::Rejected->value])->count()),
            Stat::make('Results to enter', (string) $count(WorkStatus::Ready, WorkStatus::Amending)),
            Stat::make('Waiting for verification', (string) $count(WorkStatus::Entered)),
            Stat::make('Released today', (string) WorkItem::where('status', WorkStatus::Released->value)->where('released_at', '>=', $today)->count()),
        ];
    }
}
