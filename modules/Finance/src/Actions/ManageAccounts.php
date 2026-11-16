<?php

declare(strict_types=1);

namespace Modules\Finance\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\Finance\Permissions;
use Modules\Finance\Support\FinanceException;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Enums\PaymentMethod;

/**
 * Creates and edits accounts, and moves money between them.
 */
final class ManageAccounts
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** Add an account, with the money already in it as an opening balance. */
    public function create(string $name, PaymentMethod $type, bool $isDefault, Money $openingBalance): Account
    {
        Gate::authorize(Permissions::ACCOUNTS_MANAGE);

        if ($openingBalance->isNegative()) {
            throw new FinanceException('The opening balance cannot be negative.');
        }

        return DB::connection('clinic')->transaction(function () use ($name, $type, $isDefault, $openingBalance): Account {
            $account = Account::create(['name' => trim($name), 'type' => $type, 'is_default' => false, 'is_active' => true]);

            if ($isDefault) {
                $this->makeDefault($account);
            }

            if ($openingBalance->isPositive()) {
                $this->ledger->post($account, $openingBalance, 'opening', $account->id, 'Opening balance');
            }

            return $account;
        });
    }

    public function update(Account $account, string $name, bool $isDefault, bool $isActive): Account
    {
        Gate::authorize(Permissions::ACCOUNTS_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($account, $name, $isDefault, $isActive): Account {
            if (! $isActive && ! $this->ledger->balance($account)->isZero()) {
                throw new FinanceException('This account still holds money. Transfer it out before making the account inactive.');
            }

            $account->update(['name' => trim($name), 'is_active' => $isActive, 'is_default' => $isActive && $account->is_default]);

            if ($isDefault && $isActive) {
                $this->makeDefault($account);
            } elseif (! $isDefault && $account->is_default) {
                $account->update(['is_default' => false]);
            }

            return $account;
        });
    }

    /** Move money from one account to another, for example cash taken to the bank. */
    public function transfer(Account $from, Account $to, Money $amount, ?string $note = null): void
    {
        Gate::authorize(Permissions::ACCOUNTS_MANAGE);

        if ($from->id === $to->id) {
            throw new FinanceException('Choose two different accounts.');
        }

        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be more than zero.');
        }

        DB::connection('clinic')->transaction(function () use ($from, $to, $amount, $note): void {
            // Lock the source account so two transfers cannot both spend
            // the same balance.
            $from = Account::whereKey($from->id)->lockForUpdate()->firstOrFail();

            if ($amount->greaterThan($this->ledger->balance($from))) {
                throw new FinanceException("{$from->name} holds only {$this->ledger->balance($from)->formatted()}.");
            }

            $transferId = DB::connection('clinic')->table('fin_transfers')->insertGetId([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount->toDecimal(),
                'note' => filled($note) ? trim($note) : null,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);

            $suffix = filled($note) ? ': '.trim($note) : '';
            $this->ledger->post($from, $amount->negate(), 'transfer_out', $transferId, "Transfer to {$to->name}{$suffix}");
            $this->ledger->post($to, $amount, 'transfer_in', $transferId, "Transfer from {$from->name}{$suffix}");

            $this->audit->record('account.transfer', $from, [], [
                'from' => $from->name, 'to' => $to->name, 'amount' => $amount->toDecimal(),
            ], filled($note) ? ['note' => trim($note)] : []);
        });
    }

    /** One default account per payment method. */
    private function makeDefault(Account $account): void
    {
        Account::where('type', $account->type->value)->whereKeyNot($account->id)->where('is_default', true)->get()
            ->each(fn (Account $other) => $other->update(['is_default' => false]));

        $account->update(['is_default' => true]);
    }
}
