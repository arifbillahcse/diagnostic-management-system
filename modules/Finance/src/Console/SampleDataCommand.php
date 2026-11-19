<?php

declare(strict_types=1);

namespace Modules\Finance\Console;

use App\Core\Branches\Branch;
use App\Core\Console\SampleDataCommand as Base;
use App\Core\Money\Money;
use App\Core\Tenancy\Clinic;
use Carbon\CarbonImmutable;
use Modules\Finance\Actions\ManageAccounts;
use Modules\Finance\Actions\ManageCommission;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\CommissionRule;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use RuntimeException;

/**
 * Sample data for the Finance module: expenses in several categories (one
 * cancelled), commission rates for three referral doctors, a part payment of
 * commission, and a transfer between two accounts.
 *
 * Run it after laboratory:sample-data. The accounts and the commission come
 * from the clinic's payments and orders, which this command brings up to date
 * first.
 */
final class SampleDataCommand extends Base
{
    protected $signature = 'finance:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load sample expenses, commission rates, a commission payout and an account transfer, for trying the finance module';

    /** [days ago, category, paid from, amount, description, payee] */
    private const EXPENSES = [
        [9, 'Rent', PaymentMethod::Bank, '35000', 'Shop rent for the month', 'Haque Properties'],
        [8, 'Utilities', PaymentMethod::Bkash, '4200', 'Electricity bill', 'DESCO'],
        [7, 'Reagents and supplies', PaymentMethod::Bkash, '12600', 'Glucose and lipid reagent kits', 'Popular Diagnostics Supply'],
        [6, 'Internet and phone', PaymentMethod::Nagad, '1500', 'Broadband, monthly', 'Link3'],
        [5, 'Transport', PaymentMethod::Cash, '350', 'Courier for sample box', 'Pathao'],
        [4, 'Repairs', PaymentMethod::Card, '2800', 'Centrifuge belt and service', 'Lab Care Services'],
        [3, 'Marketing', PaymentMethod::Bkash, '3000', 'Facebook page boost', null],
        [2, 'Cleaning', PaymentMethod::Cash, '600', 'Cleaning supplies', null],
        [1, 'Reagents and supplies', PaymentMethod::Nagad, '1800', 'Gloves and tissue', 'Popular Diagnostics Supply'],
        [1, 'Utilities', PaymentMethod::Cash, '500', 'Water bill, entered twice by mistake', 'WASA'],
    ];

    protected function moduleKey(): string
    {
        return 'finance';
    }

    protected function load(Clinic $clinic): array
    {
        // Other modules may already have made expenses (a staff advance does), so only look for ours.
        if (CommissionRule::query()->exists() || ExpenseCategory::where('name', 'Rent')->exists()) {
            throw new RuntimeException('This clinic already has commission rates or sample expenses. Sample data is only for a clinic without any.');
        }

        $doctors = ReferralDoctor::orderBy('id')->limit(3)->get();

        if ($doctors->count() < 3) {
            throw new RuntimeException('There are fewer than three referral doctors. Run laboratory:sample-data first.');
        }

        // Accounts and earned commission follow the payments that already exist.
        SyncCommand::catchUp();

        $branch = Branch::orderBy('id')->firstOrFail();
        $today = CarbonImmutable::now($clinic->timezone)->startOfDay();
        $accounts = $this->accounts();

        foreach (['Rent', 'Utilities', 'Reagents and supplies', 'Internet and phone', 'Transport', 'Repairs', 'Marketing', 'Cleaning'] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $cancelled = null;

        $ledger = app(Ledger::class);

        foreach (self::EXPENSES as [$daysAgo, $category, $method, $amount, $description, $payee]) {
            // Pay from the usual account, unless it holds too little: no account goes below zero.
            $from = $accounts[$method->value];
            $from = $ledger->balance($from)->lessThan(Money::fromDecimal($amount)) ? $accounts[PaymentMethod::Bank->value] : $from;

            $cancelled = app(ManageExpenses::class)->record(
                $branch,
                ExpenseCategory::where('name', $category)->firstOrFail(),
                $from,
                Money::fromDecimal($amount),
                $today->subDays($daysAgo)->toDateString(),
                $description,
                $payee,
            );
        }

        app(ManageExpenses::class)->void($cancelled, 'Entered twice: the water bill was already recorded.');

        // Commission: a plain percent for two doctors; the third gets more on ultrasound.
        $usg = Service::where('code', 'USG-ABD')->first();
        $rates = app(ManageCommission::class);
        $rates->setRates($doctors[0], ['kind' => CommissionRule::PERCENT, 'value' => '10']);
        $rates->setRates($doctors[1], ['kind' => CommissionRule::PERCENT, 'value' => '15']);
        $rates->setRates(
            $doctors[2],
            ['kind' => CommissionRule::PERCENT, 'value' => '10'],
            $usg ? [['service_id' => $usg->id, 'kind' => CommissionRule::FIXED, 'value' => '300']] : [],
        );
        SyncCommand::catchUp();

        // Pay half of what the best-earning doctor is owed.
        $commission = app(Commission::class);
        $top = $doctors->sortByDesc(fn (ReferralDoctor $d): int => $commission->balance($d)->minor)->first();
        $owed = $commission->balance($top);
        $payout = $owed->isPositive() ? $owed->percent('50') : null;

        if ($payout !== null && $payout->isPositive()) {
            $paidFrom = $ledger->balance($accounts[PaymentMethod::Bkash->value])->lessThan($payout) ? $accounts[PaymentMethod::Bank->value] : $accounts[PaymentMethod::Bkash->value];
            app(ManageCommission::class)->pay($top, $paidFrom, $payout, 'Paid from '.$paidFrom->name.', first half of the month');
        }

        // Cash taken to the bank agent: move part of the cash to bKash.
        $cash = $accounts[PaymentMethod::Cash->value];
        $held = $ledger->balance($cash);

        if ($held->isPositive()) {
            app(ManageAccounts::class)->transfer($cash, $accounts[PaymentMethod::Bkash->value], $held->percent('50'), 'Cash sent to the bKash agent');
        }

        return [
            count(self::EXPENSES).' expenses in 8 categories, 1 cancelled (Expenses, Profit report)',
            'Commission rates for 3 doctors, 1 with a fixed rate on ultrasound (Referral commission)',
            $payout !== null ? "1 commission payout to {$top->name}, half of what is owed (Commission payouts)" : 'No commission was earned yet, so no payout was made',
            '1 transfer between accounts (Accounts)',
        ];
    }

    /** @return array<string, Account> the active account for each payment method, made when missing */
    private function accounts(): array
    {
        $found = [];

        foreach ([PaymentMethod::Cash, PaymentMethod::Bkash, PaymentMethod::Nagad, PaymentMethod::Card, PaymentMethod::Bank] as $method) {
            $found[$method->value] = Account::where('type', $method)->where('is_active', true)->orderByDesc('is_default')->first()
                ?? app(ManageAccounts::class)->create($method->label(), $method, true, $method === PaymentMethod::Bank ? Money::fromDecimal('100000') : Money::zero());
        }

        return $found;
    }
}
