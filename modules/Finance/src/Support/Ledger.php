<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\AccountEntry;
use Modules\FrontDesk\Enums\PaymentMethod;

/**
 * The money ledger: posts movements into accounts and reads balances.
 *
 * Every entry names what caused it. A cause can be posted only once, so
 * running the same posting again (a retried job, a backfill) changes nothing.
 */
final class Ledger
{
    public function post(Account $account, Money $amount, string $sourceType, int $sourceId, string $description, ?CarbonInterface $occurredAt = null): AccountEntry
    {
        $existing = AccountEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint, so losing the race to another request does not
            // disturb the caller's own transaction.
            return DB::connection('clinic')->transaction(function () use ($account, $amount, $sourceType, $sourceId, $description, $occurredAt): AccountEntry {
                $entry = AccountEntry::create([
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'occurred_at' => $occurredAt ?? now(),
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'description' => mb_substr($description, 0, 255),
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                ]);

                // Entry and running balance change together or not at all.
                // The database does the addition, so two entries posted at
                // the same moment cannot lose one another's amount.
                DB::connection('clinic')->update('UPDATE fin_accounts SET balance = balance + ? WHERE id = ?', [$amount->toDecimal(), $account->id]);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return AccountEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->firstOrFail();
        }
    }

    /**
     * The account money received by a payment method goes into. Created the
     * first time it is needed, so finance works without any set-up.
     */
    public function accountFor(PaymentMethod $method): Account
    {
        return Account::where('type', $method->value)->where('is_default', true)->where('is_active', true)->first()
            ?? Account::where('type', $method->value)->where('is_active', true)->orderBy('id')->first()
            ?? Account::create(['name' => $this->freeName($method->label()), 'type' => $method, 'is_default' => true, 'is_active' => true]);
    }

    /** What the account holds now, read fresh from the database. */
    public function balance(Account $account): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fin_accounts')->where('id', $account->id)->value('balance'));
    }

    /** @return array<int, Money> account id => balance */
    public function balances(): array
    {
        return DB::connection('clinic')->table('fin_accounts')
            ->pluck('balance', 'id')
            ->map(fn ($total): Money => MoneyCast::fromDatabase($total))
            ->all();
    }

    /**
     * Add up every account's entries and compare with its stored balance.
     * A difference is corrected and returned, so it can be reported.
     *
     * @return array<string, array{stored: string, entries: string}> account name => the two figures
     */
    public function reconcile(): array
    {
        $db = DB::connection('clinic');
        $sums = $db->table('fin_account_entries')->selectRaw('account_id, SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $differences = [];

        foreach ($db->table('fin_accounts')->get(['id', 'name', 'balance']) as $account) {
            $stored = MoneyCast::fromDatabase($account->balance);
            $entries = MoneyCast::fromDatabase($sums[$account->id] ?? 0);

            if (! $stored->equals($entries)) {
                $differences[$account->name] = ['stored' => $stored->toDecimal(), 'entries' => $entries->toDecimal()];
                $db->table('fin_accounts')->where('id', $account->id)->update(['balance' => $entries->toDecimal()]);
            }
        }

        return $differences;
    }

    private function freeName(string $name): string
    {
        $candidate = $name;

        for ($i = 2; Account::where('name', $candidate)->exists(); $i++) {
            $candidate = "{$name} {$i}";
        }

        return $candidate;
    }
}
