<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [
            AccountResource::class,
            AccountEntryResource::class,
            ExpenseResource::class,
            ExpenseCategoryResource::class,
            CommissionResource::class,
            PayoutResource::class,
        ];
    }

    /** @return list<class-string> */
    public static function pages(): array
    {
        return [ProfitReportPage::class];
    }

    /** @return list<class-string> */
    public static function widgets(): array
    {
        return [BalancesWidget::class];
    }
}
