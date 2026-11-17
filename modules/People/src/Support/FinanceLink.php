<?php

declare(strict_types=1);

namespace Modules\People\Support;

use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Money\Money;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Expense;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Support\FinanceException;

/**
 * Records money paid out (advances, salaries) as a finance expense when
 * the finance module is on. Returns the expense id, or null with finance off.
 */
final class FinanceLink
{
    public function on(): bool
    {
        return app(ModuleState::class)->isEnabled('finance');
    }

    public function expense(int $branchId, string $category, ?int $accountId, Money $amount, string $date, string $description, ?string $payee = null): ?int
    {
        if (! $this->on() || ! $amount->isPositive()) {
            return null;
        }

        $account = $accountId ? Account::where('is_active', true)->find($accountId) : null;

        if ($account === null) {
            throw new PeopleException('Choose the account the money was paid from.');
        }

        try {
            return app(ManageExpenses::class)->record(
                Branch::findOrFail($branchId),
                ExpenseCategory::firstOrCreate(['name' => $category]),
                $account,
                $amount,
                $date,
                $description,
                $payee,
            )->id;
        } catch (FinanceException $e) {
            throw new PeopleException($e->getMessage(), previous: $e);
        }
    }

    /** Void the expense recorded for something that is being undone. */
    public function voidExpense(?int $expenseId, string $reason): void
    {
        if ($expenseId === null || ! $this->on()) {
            return;
        }

        $expense = Expense::find($expenseId);

        if ($expense === null || $expense->voided_at !== null) {
            return;
        }

        try {
            app(ManageExpenses::class)->void($expense, $reason);
        } catch (FinanceException $e) {
            throw new PeopleException($e->getMessage(), previous: $e);
        }
    }
}
