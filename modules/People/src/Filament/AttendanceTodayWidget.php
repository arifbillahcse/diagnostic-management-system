<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Branches\CurrentBranch;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Tenancy\ClinicContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Modules\People\Models\Attendance;
use Modules\People\Models\Staff;
use Modules\People\Permissions;

/** Today's attendance of the current branch, on the dashboard, for those who record it. */
class AttendanceTodayWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Attendance today';

    public static function moduleKey(): string
    {
        return 'people';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::ATTENDANCE_RECORD;
    }

    public static function canView(): bool
    {
        return static::moduleAllows() && Gate::allows(Permissions::ATTENDANCE_RECORD, app(CurrentBranch::class)->get()->id);
    }

    /** @return array{staff: int, marked: int, absent: int} */
    public static function figures(): array
    {
        $today = now(app(ClinicContext::class)->timezone())->toDateString();
        $ids = Staff::where('branch_id', app(CurrentBranch::class)->get()->id)->employedBetween($today, $today)->pluck('id');
        $marks = Attendance::whereIn('staff_id', $ids)->whereDate('date', $today)->pluck('status');

        return ['staff' => $ids->count(), 'marked' => $marks->count(), 'absent' => $marks->filter(fn ($s): bool => $s === 'absent')->count()];
    }

    protected function getStats(): array
    {
        $f = static::figures();
        $missing = $f['staff'] - $f['marked'];

        return [
            Stat::make('Not marked yet', (string) $missing)
                ->description("of {$f['staff']} staff")
                ->color($missing > 0 ? 'warning' : 'success')
                ->url(DailyAttendance::getUrl()),
            Stat::make('Absent', (string) $f['absent'])->color($f['absent'] > 0 ? 'danger' : 'gray')->url(DailyAttendance::getUrl()),
        ];
    }
}
