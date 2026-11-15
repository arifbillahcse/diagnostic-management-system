<?php

declare(strict_types=1);

namespace Modules\Demo\Filament;

use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Demo\DemoModule;
use Modules\Demo\Models\DemoNote;

/** Shows how a module adds a widget to the shared dashboard. */
class DemoNotesWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    public static function moduleKey(): string
    {
        return 'demo';
    }

    public static function permissionKey(): ?string
    {
        return DemoModule::NOTES_MANAGE;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Demo notes', (string) DemoNote::count())->description('Added by the demo module'),
        ];
    }
}
