<?php

declare(strict_types=1);

namespace Modules\People\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Permission;
use App\Core\Auth\Role;
use App\Core\Branches\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\People\Actions\ManageStaff;
use Modules\People\Actions\RunPayroll;
use Modules\People\Models\SalaryChange;
use Modules\People\Models\Staff;
use Modules\People\Support\PeopleException;

class StaffTest extends PeopleTestCase
{
    public function test_adding_staff_gives_a_number_a_first_salary_row_and_an_audit_entry(): void
    {
        $karim = $this->person();
        $rina = $this->person('Rina Akter', '12000.50');

        $this->assertSame('EMP-0001', $karim->staff_no);
        $this->assertSame('EMP-0002', $rina->staff_no);
        $this->assertSame('12,000.50', $rina->monthly_salary->formatted());
        $this->assertSame(['Joined' => '15000.00'], SalaryChange::where('staff_id', $karim->id)->pluck('monthly_salary', 'reason')->map->toDecimal()->all());
        $this->assertTrue(AuditLog::where('action', 'staff.added')->where('entity_id', $karim->id)->exists());
    }

    public function test_bad_details_are_refused(): void
    {
        foreach ([
            [['name' => ''], 'name and the designation'],
            [['joined_on' => '2026-12-01'], 'future'],
            [['joined_on' => '2026-02-30'], 'not a valid date'],
            [['monthly_salary' => '-5'], 'amount such as'],
            [['monthly_salary' => '15000.555'], 'amount such as'],
        ] as [$change, $message]) {
            try {
                app(ManageStaff::class)->add($change + ['name' => 'X', 'designation' => 'Cleaner', 'branch_id' => $this->branch->id, 'joined_on' => '2026-01-01', 'monthly_salary' => '100']);
                $this->fail('Accepted: '.json_encode($change));
            } catch (PeopleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, Staff::count());
    }

    public function test_a_salary_change_is_a_new_row_and_the_old_salary_still_applies_to_earlier_days(): void
    {
        $karim = $this->person();

        app(ManageStaff::class)->changeSalary($karim, '16500', '2026-07-01', 'Yearly increment');

        $karim->refresh();
        $this->assertSame('16,500.00', $karim->monthly_salary->formatted());
        $this->assertSame('15000.00', $karim->salaryOn('2026-06-30')->toDecimal());
        $this->assertSame('16500.00', $karim->salaryOn('2026-07-01')->toDecimal());
        $this->assertSame(2, SalaryChange::where('staff_id', $karim->id)->count());

        $entry = AuditLog::where('action', 'staff.salary_changed')->sole();
        $this->assertSame(['monthly_salary' => '15000.00'], $entry->before);
        $this->assertSame('16500.00', $entry->after['monthly_salary']);

        foreach ([
            ['17000', '2026-06-01', 'Back', 'cannot start before'],
            ['17000', '2026-12-01', 'Later', 'future'],
            ['16500', '2026-08-01', 'Same', 'already'],
            ['17000', '2026-08-01', '', 'Say why'],
        ] as [$amount, $from, $reason, $message]) {
            try {
                app(ManageStaff::class)->changeSalary($karim, $amount, $from, $reason);
                $this->fail("Accepted {$amount} from {$from}");
            } catch (PeopleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_salaries_need_their_own_permission(): void
    {
        $karim = $this->person();
        $this->actingAs($this->staff('admin'));
        app(ManageStaff::class)->changeSalary($karim, '16000', '2026-11-01', 'Increment');

        $hr = $this->userWithRoles('receptionist');
        $this->actingAs($hr);
        $this->expectException(AuthorizationException::class);
        app(ManageStaff::class)->changeSalary($karim, '17000', '2026-11-01', 'Increment');
    }

    public function test_someone_who_may_manage_staff_but_not_salaries_can_add_without_a_salary_only(): void
    {
        $role = Role::create(['key' => 'office', 'name' => 'Office']);
        $role->permissions()->sync(Permission::whereIn('key', ['people.staff.view', 'people.staff.manage'])->pluck('id'));
        $user = $this->userWithRoles('office');
        $this->actingAs($user);

        $this->assertSame('0.00', $this->person('Cleaner', '0')->monthly_salary->toDecimal());

        $this->expectException(AuthorizationException::class);
        $this->person('Guard', '9000');
    }

    public function test_details_are_edited_with_an_audit_entry_of_what_changed(): void
    {
        $karim = $this->person();

        app(ManageStaff::class)->update($karim, ['designation' => 'Senior lab technician', 'phone' => '01811000000']);

        $entry = AuditLog::where('action', 'staff.updated')->sole();
        $this->assertEquals(['designation' => 'Lab technician', 'phone' => '01711000000'], $entry->before);
        $this->assertEquals(['designation' => 'Senior lab technician', 'phone' => '01811000000'], $entry->after);

        app(ManageStaff::class)->update($karim->fresh(), ['designation' => 'Senior lab technician']);
        $this->assertSame(1, AuditLog::where('action', 'staff.updated')->count());
    }

    public function test_the_joining_date_cannot_move_past_recorded_attendance(): void
    {
        $karim = $this->person();
        $this->mark('2026-03-10', [$karim->id => 'present']);

        $this->expectExceptionMessage('Attendance is recorded before that joining date');
        app(ManageStaff::class)->update($karim, ['joined_on' => '2026-04-01']);
    }

    public function test_leaving_and_coming_back_go_through_their_actions(): void
    {
        $karim = $this->person();
        $this->mark('2026-11-10', [$karim->id => 'present']);

        try {
            app(ManageStaff::class)->markLeft($karim, '2026-11-05', 'Resigned');
            $this->fail('Left before recorded attendance');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('after that day', $e->getMessage());
        }

        app(ManageStaff::class)->markLeft($karim, '2026-11-15', 'Resigned');
        $karim->refresh();
        $this->assertTrue($karim->hasLeft());
        $this->assertFalse($karim->worksOn('2026-11-16'));
        $this->assertTrue($karim->worksOn('2026-11-15'));

        try {
            app(ManageStaff::class)->changeSalary($karim, '20000', '2026-11-01', 'Raise');
            $this->fail('Salary changed after leaving');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('has left', $e->getMessage());
        }

        try {
            app(ManageStaff::class)->markLeft($karim, '2026-11-15', 'Again');
            $this->fail('Left twice');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('already', $e->getMessage());
        }

        app(ManageStaff::class)->rejoin($karim, 'Marked by mistake');
        $this->assertFalse($karim->fresh()->hasLeft());
        $this->assertSame(['left', 'working'], AuditLog::whereIn('action', ['staff.left', 'staff.rejoined'])->orderBy('id')->get()->map(fn ($e) => $e->after['state'])->all());
    }

    public function test_salary_history_rows_cannot_be_changed(): void
    {
        $this->person();

        foreach ([fn () => SalaryChange::first()->update(['monthly_salary' => '1']), fn () => SalaryChange::first()->delete()] as $try) {
            try {
                $try();
                $this->fail('A salary history row was changed.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_moving_branch_waits_for_the_month_to_be_settled_in_the_old_one(): void
    {
        $karim = $this->person();
        $other = Branch::create(['code' => 'B2', 'name' => 'Second branch']);
        $this->mark('2026-11-03', [$karim->id => 'present']);

        try {
            app(ManageStaff::class)->update($karim, ['branch_id' => $other->id]);
            $this->fail('Moved mid-month');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('Approve this month', $e->getMessage());
        }

        app(RunPayroll::class)->approve(app(RunPayroll::class)->start($this->branch, '2026-11'));
        app(ManageStaff::class)->update($karim->fresh(), ['branch_id' => $other->id]);
        $this->assertSame($other->id, $karim->fresh()->branch_id);
    }
}
