<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Branches\Branch;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\People\Actions\RecordAttendance;
use Modules\People\Models\Attendance;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use UnitEnum;

/**
 * One day's attendance sheet for one branch: a mark per person, with in
 * and out times if wanted. Marks can be changed later; every change is
 * audited.
 */
class DailyAttendance extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Daily attendance';

    protected static ?string $title = 'Daily attendance';

    protected static ?string $slug = 'staff/attendance';

    protected string $view = 'people::daily';

    public string $date = '';

    public ?int $branchId = null;

    /** @var array<int|string, array{status: ?string, in_time: ?string, out_time: ?string, note: ?string}> */
    public array $rows = [];

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
        $this->date = $this->today();
        $this->branchId = Auth::user()?->default_branch_id ?? Branch::orderBy('id')->value('id');
        $this->load();
    }

    public function today(): string
    {
        return now(app(ClinicContext::class)->timezone())->toDateString();
    }

    public function updatedDate(): void
    {
        if ($this->date > $this->today() || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = $this->today();
        }
        $this->load();
    }

    public function updatedBranchId(): void
    {
        $this->load();
    }

    public function previousDay(): void
    {
        $this->date = CarbonImmutable::parse($this->date)->subDay()->toDateString();
        $this->load();
    }

    public function nextDay(): void
    {
        $next = CarbonImmutable::parse($this->date)->addDay()->toDateString();
        $this->date = $next > $this->today() ? $this->today() : $next;
        $this->load();
    }

    public function canRecord(): bool
    {
        return $this->branchId !== null && Gate::allows(Permissions::ATTENDANCE_RECORD, $this->branchId);
    }

    /** @return Collection<int, Staff> */
    public function getStaffProperty(): Collection
    {
        if ($this->branchId === null) {
            return collect();
        }

        return Staff::where('branch_id', $this->branchId)->employedBetween($this->date, $this->date)->orderBy('name')->get();
    }

    /** @return array<int, string> */
    public function getBranchesProperty(): array
    {
        return Branch::orderBy('name')->pluck('name', 'id')->all();
    }

    public function markAllPresent(): void
    {
        foreach ($this->rows as $id => $row) {
            if (blank($row['status'])) {
                $this->rows[$id]['status'] = 'present';
            }
        }
    }

    public function save(): void
    {
        $branch = Branch::find($this->branchId);

        if ($branch === null) {
            return;
        }

        $saved = Guard::run(null, fn () => app(RecordAttendance::class)->day($branch, $this->date, $this->rows), 'Attendance saved');

        if ($saved !== null) {
            $this->load();
        }
    }

    private function load(): void
    {
        unset($this->staff);

        $marks = Attendance::whereIn('staff_id', $this->staff->modelKeys())->whereDate('date', $this->date)->get()->keyBy('staff_id');

        $this->rows = [];

        foreach ($this->staff as $person) {
            $mark = $marks->get($person->id);
            $this->rows[$person->id] = [
                'status' => $mark?->status,
                'in_time' => $mark?->in_time ? substr($mark->in_time, 0, 5) : null,
                'out_time' => $mark?->out_time ? substr($mark->out_time, 0, 5) : null,
                'note' => $mark?->note,
            ];
        }
    }
}
