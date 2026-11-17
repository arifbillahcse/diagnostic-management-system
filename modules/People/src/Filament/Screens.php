<?php

declare(strict_types=1);

namespace Modules\People\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [StaffResource::class];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [DailyAttendance::class, MonthlyAttendance::class];
    }
}
