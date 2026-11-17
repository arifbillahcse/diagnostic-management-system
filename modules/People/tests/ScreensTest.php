<?php

declare(strict_types=1);

namespace Modules\People\Tests;

use App\Core\Auth\Permission;
use App\Core\Auth\Role;
use Livewire\Livewire;
use Modules\People\Filament\DailyAttendance;
use Modules\People\Filament\ListStaff;
use Modules\People\Models\Attendance;
use Modules\People\Models\Staff;

class ScreensTest extends PeopleTestCase
{
    public function test_staff_are_added_and_managed_from_the_list(): void
    {
        $this->signIn($this->ownerUser)->get(self::BASE.'/staff')->assertOk();

        Livewire::test(ListStaff::class)
            ->callAction('add', ['name' => 'Karim Mia', 'designation' => 'Lab technician', 'branch_id' => $this->branch->id, 'joined_on' => '2026-01-01', 'monthly_salary' => '15000'])
            ->assertNotified('Staff member added');

        $karim = Staff::sole();

        Livewire::test(ListStaff::class)
            ->assertCanSeeTableRecords([$karim])
            ->assertSee('15,000.00')
            ->callTableAction('salary', $karim, ['monthly_salary' => '16000', 'effective_from' => '2026-11-01', 'reason' => 'Increment'])
            ->assertNotified('Salary changed')
            ->callTableAction('edit', $karim, ['name' => 'Karim Mia', 'designation' => 'Senior technician', 'branch_id' => $this->branch->id, 'joined_on' => '2026-01-01'])
            ->assertNotified('Details saved')
            ->callTableAction('left', $karim, ['left_on' => '2026-11-15', 'reason' => 'Resigned'])
            ->assertNotified('Marked as left')
            ->assertCanNotSeeTableRecords([$karim]);

        $karim->refresh();
        $this->assertSame('16000.00', $karim->monthly_salary->toDecimal());
        $this->assertSame('Senior technician', $karim->designation);
        $this->assertTrue($karim->hasLeft());
    }

    public function test_a_refusal_keeps_the_form_open_with_the_reason(): void
    {
        $karim = $this->person();

        Livewire::test(ListStaff::class)
            ->callTableAction('salary', $karim, ['monthly_salary' => '15000', 'effective_from' => '2026-11-01', 'reason' => 'Same'])
            ->assertNotified('Not done');

        $this->assertSame(1, $karim->salaryChanges()->count());
    }

    public function test_people_without_salary_access_do_not_see_salaries(): void
    {
        $karim = $this->person();
        $role = Role::create(['key' => 'office', 'name' => 'Office']);
        $role->permissions()->sync(Permission::whereIn('key', ['people.staff.view', 'people.staff.manage', 'people.attendance.record'])->pluck('id'));
        $this->actingAs($this->userWithRoles('office'));

        Livewire::test(ListStaff::class)
            ->assertCanSeeTableRecords([$karim])
            ->assertDontSee('15,000.00')
            ->assertTableActionHidden('salary', $karim)
            ->assertTableActionHidden('history', $karim)
            ->assertTableActionVisible('edit', $karim);

        $this->signIn($this->staff('receptionist'))->get(self::BASE.'/staff')->assertForbidden();
    }

    public function test_the_daily_sheet_saves_marks(): void
    {
        $karim = $this->person();
        $rina = $this->person('Rina Akter');

        $this->signIn($this->ownerUser)->get(self::BASE.'/staff/attendance')->assertOk()->assertSee('Karim Mia');

        Livewire::test(DailyAttendance::class)
            ->assertSet('date', '2026-11-17')
            ->set("rows.{$karim->id}.status", 'late')
            ->set("rows.{$karim->id}.in_time", '09:45')
            ->call('markAllPresent')
            ->assertSet("rows.{$rina->id}.status", 'present')
            ->call('save')
            ->assertNotified('Attendance saved');

        $this->assertSame(['late', 'present'], Attendance::orderBy('staff_id')->pluck('status')->all());

        Livewire::test(DailyAttendance::class)
            ->assertSet("rows.{$karim->id}.in_time", '09:45')
            ->set('date', '2026-12-01')
            ->assertSet('date', '2026-11-17')
            ->call('previousDay')
            ->assertSet("rows.{$karim->id}.status", null);
    }

    public function test_the_sheet_is_read_only_without_the_permission_and_the_month_opens(): void
    {
        $karim = $this->person();
        $this->mark('2026-11-03', [$karim->id => 'absent']);

        $this->actingAs($this->staff('accountant'));
        Livewire::test(DailyAttendance::class)->assertDontSee('Save attendance');

        $this->signIn($this->staff('accountant'))->get(self::BASE.'/staff/attendance-month')->assertOk()->assertSee('Karim Mia')->assertSee('Not marked');
    }
}
