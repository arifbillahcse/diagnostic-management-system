<?php

declare(strict_types=1);

namespace Modules\Finance;

/**
 * Permission keys of the finance module.
 */
final class Permissions
{
    public const ACCOUNTS_VIEW = 'finance.accounts.view';

    /** Create and edit accounts, and move money between them. */
    public const ACCOUNTS_MANAGE = 'finance.accounts.manage';

    public const EXPENSES_RECORD = 'finance.expenses.record';

    public const EXPENSES_VOID = 'finance.expenses.void';

    public const COMMISSION_VIEW = 'finance.commission.view';

    /** Set each doctor's commission rates. */
    public const COMMISSION_MANAGE = 'finance.commission.manage';

    public const COMMISSION_PAY = 'finance.commission.pay';

    public const REPORTS_VIEW = 'finance.reports.view';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::ACCOUNTS_VIEW => 'View accounts, balances, and the money ledger',
            self::ACCOUNTS_MANAGE => 'Manage accounts and transfer money between them',
            self::EXPENSES_RECORD => 'Record expenses and manage expense categories',
            self::EXPENSES_VOID => 'Void an expense',
            self::COMMISSION_VIEW => 'View referral commission',
            self::COMMISSION_MANAGE => 'Set referral commission rates',
            self::COMMISSION_PAY => 'Pay referral commission',
            self::REPORTS_VIEW => 'View the profit report',
        ];
    }
}
