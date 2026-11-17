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

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::STAFF_VIEW => 'View the staff list and attendance',
            self::STAFF_MANAGE => 'Add staff, edit their details, and mark them as left',
            self::SALARY_MANAGE => 'See and change staff salaries',
            self::ATTENDANCE_RECORD => 'Record and correct daily attendance',
        ];
    }
}
