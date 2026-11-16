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
            return DB::connection('clinic')->transaction(fn (): AccountEntry => AccountEntry::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'occurred_at' => $occurredAt ?? now(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => mb_substr($description, 0, 255),
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]));
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

    public function balance(Account $account): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fin_account_entries')->where('account_id', $account->id)->sum('amount'));
    }

    /** @return array<int, Money> account id => balance */
    public function balances(): array
    {
        return DB::connection('clinic')->table('fin_account_entries')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id')
            ->map(fn ($total): Money => MoneyCast::fromDatabase($total))
            ->all();
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
