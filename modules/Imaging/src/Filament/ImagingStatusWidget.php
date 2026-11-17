<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use App\Core\Branches\CurrentBranch;
use App\Core\Modules\GatesModuleAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Permissions;

/** Imaging reports waiting, on the dashboard, for the doctors who write them. */
class ImagingStatusWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Imaging reports';

    public static function moduleKey(): string
    {
        return 'imaging';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::REPORTS_WRITE;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    /** @return array{pending: int, drafts: int, late: int} */
    public static function figures(): array
    {
        $scope = app(CurrentBranch::class)->scope();
        $open = fn (): Builder => Study::query()->when($scope !== null, fn (Builder $q) => $q->whereIn('branch_id', $scope));

        return [
            'pending' => $open()->where('status', 'pending')->count(),
            'drafts' => $open()->whereIn('status', ['draft', 'amending'])->count(),
            'late' => $open()->whereIn('status', ['pending', 'draft', 'amending'])
                ->whereHas('order', fn (Builder $q) => $q->whereNotNull('delivery_at')->where('delivery_at', '<', now()))->count(),
        ];
    }

    protected function getStats(): array
    {
        $f = static::figures();
        $list = StudyResource::getUrl('index');

        return [
            Stat::make('To report', (string) $f['pending'])->color($f['pending'] > 0 ? 'warning' : 'gray')->url($list),
            Stat::make('Drafts not signed', (string) $f['drafts'])->color($f['drafts'] > 0 ? 'info' : 'gray')->url($list),
            Stat::make('Past delivery time', (string) $f['late'])->color($f['late'] > 0 ? 'danger' : 'gray')->url($list),
        ];
    }
}
