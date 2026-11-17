<?php

declare(strict_types=1);

namespace Modules\People;

/**
 * Permission keys of the staff and attendance module.
 */
final class Permissions
{
    /** See the staff list and attendance. Salaries stay hidden. */
    public const STAFF_VIEW = 'people.staff.view';

    /** Add staff, edit their details, mark them as left or back. */
    public const STAFF_MANAGE = 'people.staff.manage';

    /** See and change salaries. */
    public const SALARY_MANAGE = 'people.salary.manage';

    /** Record and correct daily attendance. */
    public const ATTENDANCE_RECORD = 'people.attendance.record';

    /** Give and void salary advances. */
    public const ADVANCES_MANAGE = 'people.advances.manage';

    /** Start a salary run, set deductions, refresh it, cancel a draft. */
    public const PAYROLL_PREPARE = 'people.payroll.prepare';

    /** Approve a salary run (locks it and the month's attendance), or cancel an approved one. */
    public const PAYROLL_APPROVE = 'people.payroll.approve';

    /** Mark an approved salary run paid (with finance on, this records an expense). */
    public const PAYROLL_PAY = 'people.payroll.pay';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::STAFF_VIEW => 'View the staff list and attendance',
            self::STAFF_MANAGE => 'Add staff, edit their details, and mark them as left',
            self::SALARY_MANAGE => 'See and change staff salaries',
            self::ATTENDANCE_RECORD => 'Record and correct daily attendance',
            self::ADVANCES_MANAGE => 'Give and void salary advances',
            self::PAYROLL_PREPARE => 'Prepare salary runs: deductions and advance recovery',
            self::PAYROLL_APPROVE => 'Approve or cancel an approved salary run',
            self::PAYROLL_PAY => 'Mark a salary run paid',
        ];
    }
}
