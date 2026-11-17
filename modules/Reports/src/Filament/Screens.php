<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [ReportResource::class];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [ReportSettings::class];
    }
}
