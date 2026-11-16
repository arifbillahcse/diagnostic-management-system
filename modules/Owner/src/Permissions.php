<?php

declare(strict_types=1);

namespace Modules\Owner;

final class Permissions
{
    public const DASHBOARD_VIEW = 'owner.dashboard.view';

    public const REPORTS_VIEW = 'owner.reports.view';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::DASHBOARD_VIEW => 'See the month\'s figures and the collections trend on the dashboard',
            self::REPORTS_VIEW => 'View sales reports',
        ];
    }
}
