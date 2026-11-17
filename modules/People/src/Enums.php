<?php

declare(strict_types=1);

namespace Modules\People;

final class Enums
{
    /** Attendance marks. */
    public const STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'half_day' => 'Half day',
        'absent' => 'Absent',
        'leave' => 'On leave',
        'off' => 'Weekly off',
        'holiday' => 'Holiday',
    ];

    /** Short marks for the month grid. */
    public const SHORT = [
        'present' => 'P', 'late' => 'L', 'half_day' => 'H', 'absent' => 'A', 'leave' => 'LV', 'off' => 'O', 'holiday' => 'HD',
    ];

    /** Marks that mean the person came to work. */
    public const WORKED = ['present', 'late', 'half_day'];

    public static function status(string $key): string
    {
        return self::STATUSES[$key] ?? $key;
    }
}
