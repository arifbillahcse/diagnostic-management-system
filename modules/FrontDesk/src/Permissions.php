<?php

declare(strict_types=1);

namespace Modules\FrontDesk;

/**
 * Permission and limit keys of the front desk module.
 */
final class Permissions
{
    public const CATALOG_MANAGE = 'frontdesk.catalog.manage';

    public const ORDERS_VIEW = 'frontdesk.orders.view';

    public const ORDERS_CREATE = 'frontdesk.orders.create';

    /** Cancel a whole order that has no money against it. */
    public const ORDERS_CANCEL = 'frontdesk.orders.cancel';

    /** Add or cancel individual tests after the invoice is issued. */
    public const ORDERS_ADJUST = 'frontdesk.orders.adjust';

    public const DISCOUNTS_APPLY = 'frontdesk.discounts.apply';

    /** Give a discount above the role's limit. */
    public const DISCOUNTS_OVERRIDE = 'frontdesk.discounts.override';

    public const PAYMENTS_RECORD = 'frontdesk.payments.record';

    public const REFUNDS_REQUEST = 'frontdesk.refunds.request';

    public const REFUNDS_APPROVE = 'frontdesk.refunds.approve';

    /** Open and close one's own cash session. */
    public const CASH_MANAGE = 'frontdesk.cash.manage';

    /** See and close everyone's cash sessions. */
    public const CASH_VIEW_ALL = 'frontdesk.cash.view_all';

    public const SETTINGS_MANAGE = 'frontdesk.settings.manage';

    /** Role limit: largest discount, as a percent of the order subtotal. */
    public const LIMIT_DISCOUNT_PERCENT = 'frontdesk.discount.max_percent';

    /** Setting: must a refund be approved by someone other than the requester? */
    public const SETTING_REFUND_APPROVAL = 'frontdesk.refund_requires_approval';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::CATALOG_MANAGE => 'Manage services, packages, prices, and referral doctors',
            self::ORDERS_VIEW => 'View orders, invoices, and dues',
            self::ORDERS_CREATE => 'Create orders',
            self::ORDERS_CANCEL => 'Cancel an order that has no payment',
            self::ORDERS_ADJUST => 'Add or cancel tests on an issued invoice',
            self::DISCOUNTS_APPLY => 'Give discounts up to the role limit',
            self::DISCOUNTS_OVERRIDE => 'Give discounts above the role limit',
            self::PAYMENTS_RECORD => 'Record payments',
            self::REFUNDS_REQUEST => 'Request refunds',
            self::REFUNDS_APPROVE => 'Approve or reject refunds',
            self::CASH_MANAGE => 'Open and close own cash session',
            self::CASH_VIEW_ALL => 'View and close all cash sessions',
            self::SETTINGS_MANAGE => 'Change front desk settings',
        ];
    }
}
