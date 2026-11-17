<?php

declare(strict_types=1);

namespace Modules\People\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Enums;
use Modules\People\Models\Attendance;
use Modules\People\Models\SalaryChange;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\PeopleException;

/**
 * Everything that changes a staff record: adding someone, editing their
 * details, changing their salary, and marking them as left or back.
 * Each change is audited in the same transaction.
 *
 * A staff member is "working" or "left". Only markLeft() and rejoin()
 * move between the two.
 */
final class ManageStaff
{
    private const DETAILS = ['name', 'designation', 'phone', 'branch_id', 'user_id', 'joined_on', 'notes'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly ClinicContext $clinics,
    ) {}

    /** @param array<string, mixed> $data name, designation, phone, branch_id, user_id, joined_on, notes, monthly_salary */
    public function add(array $data): Staff
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? 0);
        Gate::authorize(Permissions::STAFF_MANAGE, $branch->id);

        $salary = $this->money($data['monthly_salary'] ?? '0');

        if (! $salary->isZero()) {
            Gate::authorize(Permissions::SALARY_MANAGE, $branch->id);
        }

        $details = $this->details($data);
        $this->notFuture($details['joined_on'], 'The joining date');

        return DB::connection('clinic')->transaction(function () use ($branch, $details, $salary): Staff {
            $staff = Staff::create($details + [
                'staff_no' => $this->numbers->generate('staff', $branch),
                'monthly_salary' => $salary->toDecimal(),
            ]);

            SalaryChange::create([
                'staff_id' => $staff->id,
                'monthly_salary' => $salary->toDecimal(),
                'effective_from' => $details['joined_on'],
                'reason' => 'Joined',
                'created_by' => Auth::id(),
            ]);

            $this->audit->record('staff.added', $staff, [], $this->snapshot($staff) + ['monthly_salary' => $salary->toDecimal()]);

            return $staff;
        });
    }

    /** @param array<string, mixed> $data the details only; the salary has its own action */
    public function update(Staff $staff, array $data): Staff
    {
        Gate::authorize(Permissions::STAFF_MANAGE, $staff->branch_id);

        $details = $this->details($data + ['joined_on' => $staff->joined_on->toDateString()] + $staff->only(self::DETAILS));
        Gate::authorize(Permissions::STAFF_MANAGE, (int) $details['branch_id']);
        $this->notFuture($details['joined_on'], 'The joining date');

        return DB::connection('clinic')->transaction(function () use ($staff, $details): Staff {
            $staff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();

            if ($details['joined_on'] !== $staff->joined_on->toDateString()) {
                if (Attendance::where('staff_id', $staff->id)->whereDate('date', '<', $details['joined_on'])->exists()) {
                    throw new PeopleException('Attendance is recorded before that joining date.');
                }
                if ($staff->left_on && $staff->left_on->toDateString() < $details['joined_on']) {
                    throw new PeopleException('The joining date is after the leaving date.');
                }
                if (SalaryChange::where('staff_id', $staff->id)->where('reason', '!=', 'Joined')->whereDate('effective_from', '<', $details['joined_on'])->exists()) {
                    throw new PeopleException('A salary change starts before that joining date.');
                }
            }

            if ((int) $details['branch_id'] !== $staff->branch_id) {
                $this->checkBranchMove($staff);
            }

            $before = $this->snapshot($staff);
            $staff->fill($details)->save();
            $after = $this->snapshot($staff);

            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed !== []) {
                $this->audit->record('staff.updated', $staff, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }

            return $staff;
        });
    }

    public function changeSalary(Staff $staff, string $amount, string $effectiveFrom, string $reason): Staff
    {
        Gate::authorize(Permissions::SALARY_MANAGE, $staff->branch_id);

        $salary = $this->money($amount);
        $from = $this->date($effectiveFrom, 'The start date');
        $this->notFuture($from, 'The start date');

        if (blank($reason)) {
            throw new PeopleException('Say why the salary changes, for example "yearly increment".');
        }
        if ($staff->hasLeft()) {
            throw new PeopleException("{$staff->name} has left. Mark them as back first.");
        }

        return DB::connection('clinic')->transaction(function () use ($staff, $salary, $from, $reason): Staff {
            $staff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();

            if ($from < $staff->joined_on->toDateString()) {
                throw new PeopleException('The start date is before the joining date.');
            }

            if (RunPayroll::monthSettled($staff->branch_id, substr($from, 0, 7))) {
                throw new PeopleException('The salary run for that month is approved. Start the change from a later month.');
            }

            $last = $staff->salaryChanges()->reorder('effective_from', 'desc')->orderByDesc('id')->first();

            if ($last && $from < $last->effective_from->toDateString()) {
                throw new PeopleException('The last salary change started on '.$last->effective_from->format('d M Y').'. A new one cannot start before it.');
            }
            if ($last && $last->monthly_salary->equals($salary)) {
                throw new PeopleException('The salary is already '.$salary->formatted().'.');
            }

            $old = $staff->monthly_salary;

            SalaryChange::create([
                'staff_id' => $staff->id,
                'monthly_salary' => $salary->toDecimal(),
                'effective_from' => $from,
                'reason' => trim($reason),
                'created_by' => Auth::id(),
            ]);

            $staff->forceFill(['monthly_salary' => $salary->toDecimal()])->save();

            $this->audit->record('staff.salary_changed', $staff, ['monthly_salary' => $old->toDecimal()], [
                'monthly_salary' => $salary->toDecimal(), 'effective_from' => $from,
            ], ['reason' => trim($reason)]);

            return $staff;
        });
    }

    /** working → left */
    public function markLeft(Staff $staff, string $lastDay, string $reason): Staff
    {
        Gate::authorize(Permissions::STAFF_MANAGE, $staff->branch_id);

        $date = $this->date($lastDay, 'The last working day');

        if (blank($reason)) {
            throw new PeopleException('Say why they left, for example "resigned".');
        }

        return DB::connection('clinic')->transaction(function () use ($staff, $date, $reason): Staff {
            $staff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();

            if ($staff->hasLeft()) {
                throw new PeopleException("{$staff->name} is already marked as left.");
            }
            if ($date < $staff->joined_on->toDateString()) {
                throw new PeopleException('The last working day is before the joining date.');
            }
            if (RunPayroll::monthSettled($staff->branch_id, substr($date, 0, 7))) {
                throw new PeopleException('The salary run for that month is approved. Cancel it first, or choose a later day.');
            }
            if (Attendance::where('staff_id', $staff->id)->whereDate('date', '>', $date)->exists()) {
                throw new PeopleException('Attendance is recorded after that day.');
            }

            $staff->forceFill(['left_on' => $date, 'leave_reason' => trim($reason)])->save();

            $this->audit->record('staff.left', $staff, ['state' => 'working'], ['state' => 'left', 'left_on' => $date], ['reason' => trim($reason)]);

            return $staff;
        });
    }

    /** left → working */
    public function rejoin(Staff $staff, string $reason): Staff
    {
        Gate::authorize(Permissions::STAFF_MANAGE, $staff->branch_id);

        if (blank($reason)) {
            throw new PeopleException('Say why, for example "marked as left by mistake".');
        }

        return DB::connection('clinic')->transaction(function () use ($staff, $reason): Staff {
            $staff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();

            if (! $staff->hasLeft()) {
                throw new PeopleException("{$staff->name} is still working.");
            }

            $leftOn = $staff->left_on->toDateString();
            $staff->forceFill(['left_on' => null, 'leave_reason' => null])->save();

            $this->audit->record('staff.rejoined', $staff, ['state' => 'left', 'left_on' => $leftOn], ['state' => 'working'], ['reason' => trim($reason)]);

            return $staff;
        });
    }

    /**
     * A salary run takes staff by their branch, so someone with attendance
     * this month in their old branch moves only once that month's run there
     * is approved; otherwise the old branch would leave them out.
     */
    private function checkBranchMove(Staff $staff): void
    {
        $month = CarbonImmutable::now($this->clinics->timezone())->format('Y-m');

        $worked = Attendance::where('staff_id', $staff->id)
            ->whereDate('date', '>=', $month.'-01')->whereIn('status', Enums::WORKED)->exists();

        if ($worked && ! RunPayroll::monthSettled($staff->branch_id, $month)) {
            throw new PeopleException("{$staff->name} has worked in their current branch this month. Approve this month's salary run there first, or move them at the start of next month.");
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function details(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $designation = trim((string) ($data['designation'] ?? ''));

        if ($name === '' || $designation === '') {
            throw new PeopleException('Enter the name and the designation.');
        }

        $branchId = (int) ($data['branch_id'] ?? 0);

        if (! Branch::whereKey($branchId)->exists()) {
            throw new PeopleException('Choose the branch.');
        }

        $userId = blank($data['user_id'] ?? null) ? null : (int) $data['user_id'];

        return [
            'name' => $name,
            'designation' => $designation,
            'phone' => blank($data['phone'] ?? null) ? null : trim((string) $data['phone']),
            'branch_id' => $branchId,
            'user_id' => $userId,
            'joined_on' => $this->date((string) ($data['joined_on'] ?? ''), 'The joining date'),
            'notes' => blank($data['notes'] ?? null) ? null : trim((string) $data['notes']),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Staff $staff): array
    {
        return [
            'staff_no' => $staff->staff_no,
            'name' => $staff->name,
            'designation' => $staff->designation,
            'phone' => $staff->phone,
            'branch_id' => $staff->branch_id,
            'user_id' => $staff->user_id,
            'joined_on' => $staff->joined_on->toDateString(),
            'notes' => $staff->notes,
        ];
    }

    private function money(string $amount): Money
    {
        $amount = trim($amount);

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            throw new PeopleException('Enter the salary as an amount such as 15000 or 15000.50.');
        }

        return Money::fromDecimal($amount);
    }

    private function date(string $value, string $what): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new PeopleException("{$what} is not a valid date.");
        }

        return $value;
    }

    private function notFuture(string $date, string $what): void
    {
        if ($date > CarbonImmutable::now($this->clinics->timezone())->toDateString()) {
            throw new PeopleException("{$what} cannot be in the future.");
        }
    }
}
