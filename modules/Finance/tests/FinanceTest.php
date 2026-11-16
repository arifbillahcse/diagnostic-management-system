<?php

declare(strict_types=1);

namespace Modules\Finance\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Actions\ManageAccounts;
use Modules\Finance\Actions\ManageCommission;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\AccountEntry;
use Modules\Finance\Models\CommissionEntry;
use Modules\Finance\Models\Expense;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\FinanceException;
use Modules\Finance\Support\Ledger;
use Modules\Finance\Support\Posting;
use Modules\Finance\Support\ProfitReport;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\ReferralDoctor;

final class FinanceTest extends FinanceTestCase
{
    private function refund($order, string $amount, PaymentMethod $method = PaymentMethod::Bkash): void
    {
        $refund = app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal($amount), $method, 'Test cancelled');
        $current = auth()->user();
        $this->actingAs($this->staff('accountant'));

        if ($method === PaymentMethod::Cash) {
            $this->openCash(); // a cash refund comes out of the approver's own session
        }

        app(DecideRefund::class)->approve($refund);
        $this->actingAs($current);
    }

    private function expense(string $amount, string $account = 'Cash', ?string $date = null, string $category = 'Reagents'): Expense
    {
        return app(ManageExpenses::class)->record(
            $this->branch, $this->category($category), Account::where('name', $account)->firstOrFail(),
            Money::fromDecimal($amount), $date ?? now('Asia/Dhaka')->toDateString(), 'Monthly supply', 'Supplier Ltd',
        );
    }

    public function test_payments_land_in_the_account_of_their_method_and_refunds_come_out(): void
    {
        $this->openCash();
        $order = $this->order(['1000']);
        $this->pay($order, '600', PaymentMethod::Cash);
        $this->pay($order, '400', PaymentMethod::Bkash);

        $this->assertSame('600.00', $this->balance('Cash'));
        $this->assertSame('400.00', $this->balance('bKash'));
        $this->assertTrue(Account::where('name', 'Cash')->first()->is_default, 'accounts are created when first needed');

        $this->refund($order, '150');

        $this->assertSame('250.00', $this->balance('bKash'));
        $this->assertSame(['payment', 'payment', 'refund'], AccountEntry::orderBy('id')->pluck('source_type')->all());
    }

    public function test_posting_the_same_payment_twice_changes_nothing(): void
    {
        $payment = $this->pay($this->order(['500']), '500');

        app(Posting::class)->payment($payment);
        app(Posting::class)->payment($payment);

        $this->assertSame(1, AccountEntry::count());
        $this->assertSame('500.00', $this->balance('bKash'));
    }

    public function test_account_entries_cannot_be_edited_or_deleted(): void
    {
        $this->pay($this->order(['500']), '500');
        $entry = AccountEntry::firstOrFail();

        foreach ([fn () => $entry->update(['amount' => '1']), fn () => $entry->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Account entries must be immutable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_switching_finance_on_later_picks_up_earlier_money(): void
    {
        $this->disableModule('finance');
        $doctor = ReferralDoctor::create(['name' => 'Dr. Early']);
        $order = $this->order(['1000'], ['referral_doctor_id' => $doctor->id]);
        $this->pay($order, '1000');
        $this->assertSame(0, AccountEntry::count());

        app(ModuleManager::class)->enable('finance');

        $this->assertSame('1000.00', $this->balance('bKash'));

        $this->artisan('finance:sync')->expectsOutputToContain('0 missing account entries posted.')->assertSuccessful();
        $this->assertSame(1, AccountEntry::count());
    }

    public function test_a_stored_balance_that_drifts_from_its_entries_is_found_and_corrected(): void
    {
        $this->pay($this->order(['1000']), '1000');
        $this->assertSame('1000.00', $this->balance('bKash'));

        // Tampered with directly in the database.
        DB::connection('clinic')->table('fin_accounts')->update(['balance' => '5.00']);
        $this->assertSame('5.00', $this->balance('bKash'));

        $this->artisan('finance:sync')
            ->expectsOutputToContain('Balance of bKash corrected from 5.00 to 1000.00')
            ->assertSuccessful();

        $this->assertSame('1000.00', $this->balance('bKash'));
        $this->assertSame([], app(Ledger::class)->reconcile(), 'nothing left to correct');
    }

    public function test_an_account_can_start_with_money_and_only_one_is_the_default_per_method(): void
    {
        $accounts = app(ManageAccounts::class);

        $bank = $accounts->create('City Bank', PaymentMethod::Bank, true, Money::fromDecimal('50000'));
        $second = $accounts->create('Brac Bank', PaymentMethod::Bank, true, Money::zero());

        $this->assertSame('50000.00', $this->balance('City Bank'));
        $this->assertFalse($bank->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->pay($this->order(['300']), '300', PaymentMethod::Bank);
        $this->assertSame('300.00', $this->balance('Brac Bank'));

        try {
            $accounts->update($bank, 'City Bank', false, false);
            $this->fail('An account holding money cannot be made inactive.');
        } catch (FinanceException) {
            $this->assertTrue($bank->fresh()->is_active);
        }
    }

    public function test_money_is_transferred_between_accounts_but_never_more_than_is_there(): void
    {
        $this->openCash();
        $this->pay($this->order(['1000']), '1000', PaymentMethod::Cash);
        $accounts = app(ManageAccounts::class);
        $cash = Account::where('name', 'Cash')->first();
        $bank = $accounts->create('City Bank', PaymentMethod::Bank, true, Money::zero());

        $accounts->transfer($cash, $bank, Money::fromDecimal('700'), 'Deposit');

        $this->assertSame(['300.00', '700.00'], [$this->balance('Cash'), $this->balance('City Bank')]);
        $this->assertSame('Transfer to City Bank: Deposit', AccountEntry::where('source_type', 'transfer_out')->sole()->description);
        $this->assertSame(1, AuditLog::where('action', 'account.transfer')->count());

        foreach ([[$cash, $bank, '300.01'], [$cash, $cash, '10'], [$cash, $bank, '0']] as [$from, $to, $amount]) {
            try {
                $accounts->transfer($from, $to, Money::fromDecimal($amount));
                $this->fail("A transfer of {$amount} should be refused.");
            } catch (FinanceException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('300.00', $this->balance('Cash'));
    }

    public function test_an_expense_takes_money_out_and_voiding_puts_it_back(): void
    {
        $this->openCash();
        $this->pay($this->order(['1000']), '1000', PaymentMethod::Cash);

        $expense = $this->expense('350.50');

        $this->assertMatchesRegularExpression('/^EXP-MAIN-\d{4}-00001$/', $expense->expense_no);
        $this->assertSame('649.50', $this->balance('Cash'));
        $this->assertSame('350.50', AuditLog::where('action', 'expense.recorded')->sole()->after['amount']);

        $expenses = app(ManageExpenses::class);

        try {
            $expenses->void($expense, ' ');
            $this->fail('Voiding needs a reason.');
        } catch (FinanceException) {
            $this->addToAssertionCount(1);
        }

        $expenses->void($expense, 'Entered twice');

        $this->assertSame('1000.00', $this->balance('Cash'));
        $this->assertTrue($expense->fresh()->isVoid());

        $this->expectExceptionMessage('This expense is already void.');
        $expenses->void($expense, 'Again');
    }

    public function test_expenses_that_make_no_sense_are_refused(): void
    {
        $this->pay($this->order(['100']), '100');
        $record = fn (string $amount, string $date, string $description = 'Supplies') => app(ManageExpenses::class)->record(
            $this->branch, $this->category(), Account::firstOrFail(), Money::fromDecimal($amount), $date, $description,
        );

        foreach ([
            fn () => $record('0', now()->toDateString()),
            fn () => $record('-5', now()->toDateString()),
            fn () => $record('50', now('Asia/Dhaka')->addDays(2)->toDateString()),
            fn () => $record('50', now()->toDateString(), ' '),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Should have been refused.');
            } catch (FinanceException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Expense::count());
    }

    public function test_commission_is_earned_when_the_invoice_is_fully_paid(): void
    {
        $doctor = $this->doctor('Dr. Karim', '10');
        $order = $this->referred($doctor, ['1000', '500'], ['discount_type' => 'amount', 'discount_value' => '150', 'discount_reason' => 'Offer']);
        $commission = app(Commission::class);

        $this->pay($order, '1000');
        $this->assertSame('0.00', $commission->earned($doctor)->toDecimal(), 'part paid: nothing earned yet');

        $this->pay($order, '350');

        // 10% of what the patient paid per test: 900 and 450.
        $this->assertSame(['90.00', '45.00'], CommissionEntry::orderBy('id')->get()->map(fn ($e) => $e->amount->toDecimal())->all());
        $this->assertSame('135.00', $commission->balance($doctor)->toDecimal());

        // Settling again adds nothing.
        $commission->settle($this->invoice($order));
        $this->assertSame(2, CommissionEntry::count());
    }

    public function test_a_test_can_have_its_own_rate_and_orders_without_a_doctor_earn_nothing(): void
    {
        $doctor = $this->doctor('Dr. Karim', '10');
        $mri = $this->service('MRI', '8000');
        $cbc = $this->service('CBC', '400');
        $free = $this->service('Follow-up', '0');
        app(ManageCommission::class)->setRates($doctor, ['kind' => 'percent', 'value' => '10'], [
            ['service_id' => $mri->id, 'kind' => 'fixed', 'value' => '500'],
        ]);

        $order = $this->referred($doctor, [$mri, $cbc, $free]);
        $this->pay($order, '8400');

        $this->assertSame(['500.00', '40.00'], CommissionEntry::orderBy('id')->get()->map(fn ($e) => $e->amount->toDecimal())->all());

        $walkIn = $this->order(['1000']);
        $this->pay($walkIn, '1000');
        $this->assertSame(2, CommissionEntry::count());

        $noRate = $this->doctor('Dr. New', null);
        $this->pay($this->referred($noRate, ['1000']), '1000');
        $this->assertSame('0.00', app(Commission::class)->earned($noRate)->toDecimal());
    }

    public function test_commission_is_taken_back_when_a_test_is_cancelled(): void
    {
        $doctor = $this->doctor('Dr. Karim', '10');
        $order = $this->referred($doctor, ['1000', '500']);
        $this->pay($order, '1500');
        $this->assertSame('150.00', app(Commission::class)->earned($doctor)->toDecimal());

        app(AdjustOrder::class)->cancelItem($order->items[1], 'Not done');

        $this->assertSame('100.00', app(Commission::class)->earned($doctor)->toDecimal());
        $taken = CommissionEntry::orderByDesc('id')->first();
        $this->assertSame(['-50.00', 'Test cancelled'], [$taken->amount->toDecimal(), $taken->reason]);
    }

    public function test_a_rate_change_does_not_rewrite_commission_already_earned(): void
    {
        $doctor = $this->doctor('Dr. Karim', '10');
        $order = $this->referred($doctor, ['1000']);
        $this->pay($order, '1000');

        app(ManageCommission::class)->setRates($doctor, ['kind' => 'percent', 'value' => '25']);
        app(Commission::class)->settle($this->invoice($order));

        $this->assertSame('100.00', app(Commission::class)->earned($doctor)->toDecimal());

        $this->pay($this->referred($doctor, ['1000']), '1000');
        $this->assertSame('350.00', app(Commission::class)->earned($doctor)->toDecimal());
        $this->assertSame(['All other tests: 25.00%'], AuditLog::where('action', 'commission.rates_changed')->latest('id')->first()->after['rates']);
    }

    public function test_rates_are_validated(): void
    {
        $doctor = $this->doctor('Dr. Karim', null);
        $service = $this->service('CBC', '400');
        $set = app(ManageCommission::class);

        foreach ([
            fn () => $set->setRates($doctor, ['kind' => 'percent', 'value' => '101']),
            fn () => $set->setRates($doctor, ['kind' => 'percent', 'value' => '-5']),
            fn () => $set->setRates($doctor, ['kind' => 'share', 'value' => '5']),
            fn () => $set->setRates($doctor, null, [['service_id' => $service->id, 'kind' => 'fixed', 'value' => '5'], ['service_id' => $service->id, 'kind' => 'fixed', 'value' => '9']]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Should have been refused.');
            } catch (FinanceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_payout_reduces_what_is_owed_and_cannot_exceed_it(): void
    {
        $doctor = $this->doctor('Dr. Karim', '10');
        $this->pay($this->referred($doctor, ['2000']), '2000');
        $pay = app(ManageCommission::class);
        $bkash = Account::where('name', 'bKash')->first();

        try {
            $pay->pay($doctor, $bkash, Money::fromDecimal('200.01'));
            $this->fail('More than is owed.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('is owed 200.00', $e->getMessage());
        }

        $payout = $pay->pay($doctor, $bkash, Money::fromDecimal('120'), 'November');

        $this->assertMatchesRegularExpression('/^PAY-\d{4}-00001$/', $payout->payout_no);
        $this->assertSame('80.00', app(Commission::class)->balance($doctor)->toDecimal());
        $this->assertSame('1880.00', $this->balance('bKash'));
        $this->assertSame('80.00', AuditLog::where('action', 'commission.paid')->sole()->after['owed_after']);

        $this->expectException(FinanceException::class);
        $pay->pay($doctor, $bkash, Money::fromDecimal('80.01'));
    }

    public function test_the_profit_report_adds_up(): void
    {
        $this->openCash();
        $doctor = $this->doctor('Dr. Karim', '10');
        $a = $this->referred($doctor, ['2000']);
        $b = $this->order(['1000', '500']);
        $this->pay($a, '2000', PaymentMethod::Cash);
        $this->pay($b, '900', PaymentMethod::Bkash);
        app(AdjustOrder::class)->cancelItem($b->items[1], 'Not done');   // billed 3500 - 500, b is now paid 900 of 1000
        $this->refund($a, '100', PaymentMethod::Cash);                   // a is no longer fully paid: commission taken back
        $this->expense('300', 'Cash', null, 'Reagents');
        $this->expense('200', 'Cash', null, 'Rent');
        $voided = $this->expense('999', 'Cash', null, 'Rent');
        app(ManageExpenses::class)->void($voided, 'Mistake');

        $today = now('Asia/Dhaka')->toDateString();
        $report = app(ProfitReport::class)->for($today, $today);
        $dec = fn (string $key): string => $report[$key]->toDecimal();

        $this->assertSame('2900.00', $dec('collected'));
        $this->assertSame('100.00', $dec('refunded'));
        $this->assertSame('500.00', $dec('expenses'), 'the voided expense is left out');
        $this->assertSame('0.00', $dec('commission_paid'));
        $this->assertSame('2300.00', $dec('net'));
        $this->assertSame('3000.00', $dec('billed'));
        $this->assertSame('200.00', $dec('dues'));
        $this->assertSame('0.00', $dec('commission_owed'));
        $this->assertSame([['Reagents', '300.00'], ['Rent', '200.00']], array_map(fn ($r) => [$r['name'], $r['amount']->toDecimal()], $report['by_category']));
        $this->assertEqualsCanonicalizing([['cash', '2000.00'], ['bkash', '900.00']], array_map(fn ($r) => [$r['method'], $r['amount']->toDecimal()], $report['by_method']));

        // The accounts agree with the report: cash in minus cash out.
        $this->assertSame('1400.00', $this->balance('Cash'));
        $this->assertSame('900.00', $this->balance('bKash'));

        $yesterday = now('Asia/Dhaka')->subDay()->toDateString();
        $this->assertSame('0.00', app(ProfitReport::class)->for($yesterday, $yesterday)['collected']->toDecimal());

        $this->expectException(FinanceException::class);
        app(ProfitReport::class)->for($today, $yesterday);
    }

    public function test_finance_actions_follow_the_permissions(): void
    {
        $doctor = $this->doctor();
        $this->pay($this->order(['500']), '500');
        $account = Account::firstOrFail();
        $this->actingAs($this->staff('receptionist'));

        foreach ([
            fn () => $this->expense('10', $account->name),
            fn () => app(ManageAccounts::class)->create('Extra', PaymentMethod::Bank, false, Money::zero()),
            fn () => app(ManageCommission::class)->setRates($doctor, ['kind' => 'percent', 'value' => '50']),
            fn () => app(ManageCommission::class)->pay($doctor, $account, Money::fromDecimal('1')),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A receptionist has no finance permissions.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // But the receptionist's payments are still posted to the accounts.
        $this->pay($this->order(['300']), '300');
        $this->assertSame(2, Payment::count());
        $this->assertSame('800.00', $this->balance($account->name));
    }
}
