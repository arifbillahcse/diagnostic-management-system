<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [ItemResource::class, PurchaseResource::class, MovementResource::class, ShortfallResource::class, SupplierResource::class, UsageResource::class];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [ExpiringStockPage::class, StockUsePage::class];
    }

    /** @return list<class-string> */
    public static function widgets(): array
    {
        return [StockAlertsWidget::class];
    }
}
