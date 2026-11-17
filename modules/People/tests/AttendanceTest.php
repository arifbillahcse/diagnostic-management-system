<?php

declare(strict_types=1);

namespace Modules\People\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Branches\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\People\Actions\ManageStaff;
use Modules\People\Actions\RecordAttendance;
use Modules\People\Models\Attendance;
use Modules\People\Support\AttendanceReport;
use Modules\People\Support\PeopleException;

class AttendanceTest extends PeopleTestCase
{
    public function test_a_day_is_saved_and_only_real_changes_are_written_and_audited(): void
    {
        $karim = $this->person();
        $rina = $this->person('Rina Akter');

        $this->assertSame(2, $this->mark('2026-11-16', [
            $karim->id => ['status' => 'late', 'in_time' => '09:40', 'out_time' => '17:00'],
            $rina->id => 'absent',
        ]));
        $this->assertSame(0, $this->mark('2026-11-16', [$karim->id => ['status' => 'late', 'in_time' => '09:40', 'out_time' => '17:00'], $rina->id => 'absent']));

        $this->assertSame(1, $this->mark('2026-11-16', [$rina->id => ['status' => 'leave', 'note' => 'Sick'], $karim->id => ['status' => '']]));

        $this->assertSame('leave', Attendance::where('staff_id', $rina->id)->value('status'));
        $this->assertSame('late', Attendance::where('staff_id', $karim->id)->value('status'), 'A blank mark does not remove a saved one');

        $change = AuditLog::where('action', 'attendance.changed')->sole();
        $this->assertEquals(['status' => 'absent', 'note' => null], $change->before);
        $this->assertEquals(['status' => 'leave', 'note' => 'Sick'], $change->after);
        $this->assertSame(2, AuditLog::where('action', 'attendance.recorded')->count());
    }

    public function test_bad_marks_are_refused_and_nothing_is_saved(): void
    {
        $karim = $this->person();
        $late = $this->person('New Joiner', '1000', '2026-11-10');

        foreach ([
            ['2026-11-18', [$karim->id => 'present'], 'future'],
            ['2026-11-16', [$karim->id => 'asleep'], 'valid mark'],
            ['2026-11-16', [$karim->id => ['status' => 'absent', 'in_time' => '09:00']], 'leave the times empty'],
            ['2026-11-16', [$karim->id => ['status' => 'present', 'in_time' => '17:00', 'out_time' => '09:00']], 'after the in time'],
            ['2026-11-16', [$karim->id => ['status' => 'present', 'in_time' => '9am']], 'such as 09:30'],
            ['2026-11-05', [$karim->id => 'present', $late->id => 'present'], 'did not work here'],
        ] as [$date, $marks, $message]) {
            try {
                $this->mark($date, $marks);
                $this->fail("Accepted {$date}");
            } catch (PeopleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, Attendance::count());
    }

    public function test_staff_of_another_branch_cannot_be_marked_on_this_sheet(): void
    {
        $other = Branch::create(['code' => 'B2', 'name' => 'Second branch']);
        $karim = app(ManageStaff::class)->add(['name' => 'Karim', 'designation' => 'Cleaner', 'branch_id' => $other->id, 'joined_on' => '2026-01-01']);

        $this->expectExceptionMessage('not in this branch');
        $this->mark('2026-11-16', [$karim->id => 'present']);
    }

    public function test_recording_needs_the_permission(): void
    {
        $karim = $this->person();
        $this->actingAs($this->staff('accountant'));

        $this->expectException(AuthorizationException::class);
        app(RecordAttendance::class)->day($this->branch, '2026-11-16', [$karim->id => ['status' => 'present']]);
    }

    public function test_the_month_counts_worked_days_absences_and_unmarked_days(): void
    {
        $karim = $this->person();
        $new = $this->person('New Joiner', '1000', '2026-11-14');

        $this->mark('2026-11-01', [$karim->id => 'present']);
        $this->mark('2026-11-02', [$karim->id => 'half_day']);
        $this->mark('2026-11-03', [$karim->id => 'absent']);
        $this->mark('2026-11-04', [$karim->id => 'off']);
        $this->mark('2026-11-05', [$karim->id => 'leave']);
        $this->mark('2026-11-14', [$new->id => 'late']);

        $report = app(AttendanceReport::class)->month($this->branch->id, '2026-11');
        $this->assertCount(30, $report['days']);

        [$joiner, $k] = $report['rows']; // sorted by name: Karim Mia, New Joiner
        [$k, $joiner] = $k['staff']->name === 'Karim Mia' ? [$k, $joiner] : [$joiner, $k];

        $this->assertSame(1.5, $k['totals']['worked']);
        $this->assertSame(1, $k['totals']['absent']);
        $this->assertSame(1, $k['totals']['leave']);
        $this->assertSame(1, $k['totals']['off']);
        $this->assertSame(12, $k['totals']['unmarked'], '17 days to today, 5 marked');

        $this->assertNull($joiner['marks']['2026-11-13'], 'Not yet employed');
        $this->assertSame('late', $joiner['marks']['2026-11-14']);
        $this->assertSame(1.0, $joiner['totals']['worked']);
        $this->assertSame(3, $joiner['totals']['unmarked']);
    }

    public function test_someone_who_left_drops_out_of_later_months(): void
    {
        $karim = $this->person();
        app(ManageStaff::class)->markLeft($karim, '2026-09-30', 'Resigned');

        $this->assertCount(1, app(AttendanceReport::class)->month(null, '2026-09')['rows']);
        $this->assertCount(0, app(AttendanceReport::class)->month(null, '2026-10')['rows']);
    }
}
