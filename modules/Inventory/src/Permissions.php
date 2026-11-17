<?php

declare(strict_types=1);

namespace Modules\Inventory;

/**
 * Permission keys of the inventory module.
 */
final class Permissions
{
    /** See stock items, stock on hand, movements, and purchases. */
    public const STOCK_VIEW = 'inventory.stock.view';

    /** Create and edit stock items and suppliers. */
    public const ITEMS_MANAGE = 'inventory.items.manage';

    public const PURCHASES_RECORD = 'inventory.purchases.record';

    public const PURCHASES_VOID = 'inventory.purchases.void';

    /** Mark a purchase paid (with finance on, this records an expense). */
    public const PURCHASES_PAY = 'inventory.purchases.pay';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::STOCK_VIEW => 'View stock items, stock on hand, movements, and purchases',
            self::ITEMS_MANAGE => 'Create and edit stock items and suppliers',
            self::PURCHASES_RECORD => 'Record purchases (stock coming in)',
            self::PURCHASES_VOID => 'Void a purchase, taking its stock out again',
            self::PURCHASES_PAY => 'Mark a purchase paid',
        ];
    }
}
