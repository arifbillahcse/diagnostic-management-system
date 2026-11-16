<?php

declare(strict_types=1);

namespace Modules\Finance\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\CommissionPayout;
use Modules\Finance\Models\CommissionRule;
use Modules\Finance\Permissions;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\FinanceException;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Models\ReferralDoctor;

/**
 * Sets a doctor's commission rates and pays out what they have earned.
 */
final class ManageCommission
{
    public function __construct(
        private readonly Commission $commission,
        private readonly Ledger $ledger,
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Replace a doctor's rates. New rates apply to commission earned from
     * now on; commission already earned is not recalculated.
     *
     * @param  array{kind: string, value: string}|null  $default  for every test without its own rate
     * @param  list<array{service_id: int, kind: string, value: string}>  $overrides
     */
    public function setRates(ReferralDoctor $doctor, ?array $default, array $overrides = []): void
    {
        Gate::authorize(Permissions::COMMISSION_MANAGE);

        $rows = [];

        if ($default !== null && filled($default['value'] ?? null)) {
            $rows[] = ['service_id' => null] + $this->rule($default);
        }

        foreach ($overrides as $override) {
            $rows[] = ['service_id' => (int) $override['service_id']] + $this->rule($override);
        }

        if (count($rows) !== count(array_unique(array_column($rows, 'service_id'), SORT_REGULAR))) {
            throw new FinanceException('A test has more than one rate.');
        }

        DB::connection('clinic')->transaction(function () use ($doctor, $rows): void {
            $before = $this->describe($doctor);

            CommissionRule::where('referral_doctor_id', $doctor->id)->delete();

            foreach ($rows as $row) {
                CommissionRule::create(['referral_doctor_id' => $doctor->id] + $row);
            }

            $after = $this->describe($doctor);

            if ($before !== $after) {
                $this->audit->record('commission.rates_changed', $doctor, ['rates' => $before], ['rates' => $after]);
            }
        });
    }

    public function pay(ReferralDoctor $doctor, Account $account, Money $amount, ?string $note = null): CommissionPayout
    {
        Gate::authorize(Permissions::COMMISSION_PAY);

        if (! $amount->isPositive()) {
            throw new FinanceException('The amount must be more than zero.');
        }

        return DB::connection('clinic')->transaction(function () use ($doctor, $account, $amount, $note): CommissionPayout {
            // Lock the doctor so two payouts cannot both pay the same balance.
            $doctor = ReferralDoctor::withTrashed()->whereKey($doctor->id)->lockForUpdate()->firstOrFail();
            $owed = $this->commission->balance($doctor);

            if ($amount->greaterThan($owed)) {
                throw new FinanceException("{$doctor->name} is owed {$owed->formatted()}. A payout cannot be more than that.");
            }

            $payout = CommissionPayout::create([
                'payout_no' => $this->numbers->generate('payout'),
                'referral_doctor_id' => $doctor->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'paid_at' => now(),
                'note' => filled($note) ? trim($note) : null,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);

            $this->ledger->post($account, $amount->negate(), 'payout', $payout->id, "{$payout->payout_no}: commission to {$doctor->name}");

            $this->audit->record('commission.paid', $payout, [], [
                'payout_no' => $payout->payout_no,
                'doctor' => $doctor->name,
                'account' => $account->name,
                'amount' => $amount->toDecimal(),
                'owed_after' => $owed->minus($amount)->toDecimal(),
            ]);

            return $payout;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, value: string}
     */
    private function rule(array $input): array
    {
        $kind = $input['kind'] ?? null;
        $value = trim((string) ($input['value'] ?? ''));

        if (! in_array($kind, [CommissionRule::PERCENT, CommissionRule::FIXED], true) || ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            throw new FinanceException('A commission rate needs a kind and an amount such as 10 or 12.50.');
        }

        if ($kind === CommissionRule::PERCENT && (float) $value > 100) {
            throw new FinanceException('A commission percent cannot be more than 100.');
        }

        return ['kind' => $kind, 'value' => $value];
    }

    /** @return list<string> */
    private function describe(ReferralDoctor $doctor): array
    {
        return CommissionRule::where('referral_doctor_id', $doctor->id)->with('service')->orderBy('service_id')->get()
            ->map(fn (CommissionRule $r): string => ($r->service?->name ?? 'All other tests').': '.$r->valueText().($r->kind === CommissionRule::PERCENT ? '%' : ' fixed'))
            ->all();
    }
}
