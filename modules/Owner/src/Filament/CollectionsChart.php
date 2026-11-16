<?php

declare(strict_types=1);

namespace Modules\Owner\Filament;

use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\ChartWidget;
use Modules\FrontDesk\Support\DailyFigures;
use Modules\Owner\Permissions;
use Modules\Owner\Support\SalesReports;

/** Money collected on each of the last 14 days. */
class CollectionsChart extends ChartWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = -7;

    protected ?string $heading = 'Collections, last 14 days';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

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

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $today = app(DailyFigures::class)->today();
        $days = app(SalesReports::class)->byDay($today->subDays(13)->toDateString(), $today->toDateString());

        return [
            'labels' => array_map(fn (array $day): string => $day['date']->format('d M'), $days),
            'datasets' => [[
                'label' => 'Collected (Tk)',
                // Chart values only; every stored and printed amount stays exact.
                'data' => array_map(fn (array $day): float => $day['collected']->minor / 100, $days),
                'backgroundColor' => '#17735D',
                'borderRadius' => 4,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true]]];
    }
}
