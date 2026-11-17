<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Branches\Branch;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\People\Permissions;
use Modules\People\Support\AttendanceReport;
use UnitEnum;

class MonthlyAttendance extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Monthly attendance';

    protected static ?string $title = 'Monthly attendance';

    protected static ?string $slug = 'staff/attendance-month';

    protected string $view = 'people::monthly';

    public string $month = '';

    public ?int $branchId = null;

    public static function moduleKey(): string
    {
        return 'people';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::STAFF_VIEW;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    public function mount(): void
    {
        $this->month = now(app(ClinicContext::class)->timezone())->format('Y-m');
    }

    public function updatedMonth(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = now(app(ClinicContext::class)->timezone())->format('Y-m');
        }
    }

    /** @return array<int, string> */
    public function getBranchesProperty(): array
    {
        return Branch::orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed> */
    public function getReportProperty(): array
    {
        return app(AttendanceReport::class)->month($this->branchId, $this->month);
    }
}
