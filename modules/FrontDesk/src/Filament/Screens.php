<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [
            Orders\OrderResource::class,
            Orders\DueResource::class,
            Refunds\RefundResource::class,
            Cash\CashSessionResource::class,
            Catalog\ServiceResource::class,
            Catalog\ServiceCategoryResource::class,
            Catalog\ReferralDoctorResource::class,
        ];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [FrontDeskSettings::class];
    }

    /** @return list<class-string> */
    public static function widgets(): array
    {
        return [TodayWidget::class];
    }
}
