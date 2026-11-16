<?php

declare(strict_types=1);

namespace Modules\Finance\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Expense;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Permissions;
use Modules\Finance\Support\FinanceException;
use Modules\Finance\Support\Ledger;

/**
 * Records and voids expenses. An expense is never edited: a wrong one is
 * voided, which puts the money back, and entered again.
 */
final class ManageExpenses
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
    ) {}

    public function record(Branch $branch, ExpenseCategory $category, Account $account, Money $amount, string $date, string $description, ?string $payee = null): Expense
    {
        Gate::authorize(Permissions::EXPENSES_RECORD, $branch);

        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be more than zero.');
        }

        if (blank($description)) {
            throw new FinanceException('Say what the expense was for.');
        }

        if (! $account->is_active || ! $category->is_active) {
            throw new FinanceException('Choose an active account and category.');
        }

        $timezone = $this->clinics->require()->timezone;
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();

        if ($day->isAfter(CarbonImmutable::now($timezone))) {
            throw new FinanceException('The expense date cannot be in the future.');
        }

        return DB::connection('clinic')->transaction(function () use ($branch, $category, $account, $amount, $day, $description, $payee): Expense {
            $expense = Expense::create([
                'expense_no' => $this->numbers->generate('expense', $branch),
                'category_id' => $category->id,
                'account_id' => $account->id,
                'branch_id' => $branch->id,
                'amount' => $amount,
                'expense_date' => $day->toDateString(),
                'payee' => filled($payee) ? trim($payee) : null,
                'description' => trim($description),
                'created_by' => Auth::id(),
            ]);

            // Dated at noon on the expense day, so it lands on that day in
            // the clinic's calendar whatever the time zone offset.
            $this->ledger->post($account, $amount->negate(), 'expense', $expense->id, "{$expense->expense_no}: {$category->name}, {$expense->description}", $day->addHours(12));

            $this->audit->record('expense.recorded', $expense, [], [
                'expense_no' => $expense->expense_no,
                'category' => $category->name,
                'account' => $account->name,
                'amount' => $amount->toDecimal(),
                'date' => $day->toDateString(),
            ]);

            return $expense;
        });
    }

    public function void(Expense $expense, string $reason): Expense
    {
        Gate::authorize(Permissions::EXPENSES_VOID, $expense->branch_id);

        if (blank($reason)) {
            throw new FinanceException('A reason is required to void an expense.');
        }

        return DB::connection('clinic')->transaction(function () use ($expense, $reason): Expense {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isVoid()) {
                throw new FinanceException('This expense is already void.');
            }

            $expense->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => trim($reason)])->save();

            $this->ledger->post($expense->account, $expense->amount, 'expense_void', $expense->id, "Void of {$expense->expense_no}: ".trim($reason));

            $this->audit->record('expense.voided', $expense, [], ['expense_no' => $expense->expense_no, 'amount' => $expense->amount->toDecimal()], ['reason' => trim($reason)]);

            return $expense;
        });
    }
}
