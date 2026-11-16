<?php

declare(strict_types=1);

namespace Modules\Finance\Tests;

use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Finance\Filament\BalancesWidget;
use Modules\Finance\Filament\ListCommission;
use Modules\Finance\Filament\ListExpenses;
use Modules\Finance\Filament\ManageAccountsPage;
use Modules\Finance\Filament\ProfitReportPage;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\CommissionRule;
use Modules\Finance\Models\Expense;
use Modules\Finance\Support\Commission;
use Modules\FrontDesk\Enums\PaymentMethod;

final class ScreensTest extends FinanceTestCase
{
    private const BASE = 'http://alpha.test';

    private const PAGES = ['/finance/accounts', '/finance/entries', '/finance/expenses', '/finance/expense-categories', '/finance/commission', '/finance/payouts', '/finance/profit'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_screens_open_for_those_allowed_and_not_for_others(): void
    {
        $this->pay($this->referred($this->doctor(), ['500']), '500');

        foreach (self::PAGES as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertOk();
        }

        $accountant = $this->staff('accountant');
        $this->signIn($accountant)->get(self::BASE.'/finance/profit')->assertOk();

        $receptionist = $this->staff('receptionist');
        foreach (self::PAGES as $path) {
            $this->signIn($receptionist)->get(self::BASE.$path)->assertForbidden();
        }

        $this->actingAs($receptionist);
        $this->assertFalse(BalancesWidget::canView());
    }

    public function test_switching_finance_off_hides_its_screens(): void
    {
        $this->disableModule('finance');

        foreach (self::PAGES as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertForbidden();
        }
    }

    public function test_an_account_is_added_and_money_transferred_from_the_screen(): void
    {
        $this->openCash();
        $this->pay($this->order(['1000']), '1000', PaymentMethod::Cash);

        Livewire::test(ManageAccountsPage::class)
            ->assertSee('1,000.00')
            ->callAction('create', ['name' => 'City Bank', 'type' => 'bank', 'is_default' => true, 'opening_balance' => '5000'])
            ->assertHasNoFormErrors()
            ->assertNotified('Account added');

        $bank = Account::where('name', 'City Bank')->sole();
        $cash = Account::where('name', 'Cash')->sole();
        $this->assertSame('5000.00', $this->balance('City Bank'));

        Livewire::test(ManageAccountsPage::class)
            ->callAction('transfer', ['from' => $cash->id, 'to' => $bank->id, 'amount' => '1000.01'])
            ->assertNotified('Not done');   // the pop-up stays open for a correction

        Livewire::test(ManageAccountsPage::class)
            ->callAction('transfer', ['from' => $cash->id, 'to' => $bank->id, 'amount' => '600', 'note' => 'Deposit'])
            ->assertNotified('Money transferred');

        $this->assertSame(['400.00', '5600.00'], [$this->balance('Cash'), $this->balance('City Bank')]);

        Livewire::test(ManageAccountsPage::class)
            ->callTableAction('edit', $bank, ['name' => 'City Bank Ltd', 'is_default' => true, 'is_active' => true])
            ->assertNotified('Account saved');
        $this->assertSame('City Bank Ltd', $bank->fresh()->name);

        $this->signIn($this->ownerUser)->get(self::BASE.'/finance/entries')->assertOk()->assertSee('Transfer to City Bank: Deposit');
    }

    public function test_an_expense_is_recorded_and_voided_from_the_screen(): void
    {
        $this->pay($this->order(['1000']), '1000');
        $account = Account::firstOrFail();
        $category = $this->category('Rent');

        Livewire::test(ListExpenses::class)
            ->callAction('record', ['category_id' => $category->id, 'account_id' => $account->id, 'amount' => 'abc', 'expense_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'November rent'])
            ->assertHasFormErrors(['amount']);

        Livewire::test(ListExpenses::class)
            ->callAction('record', ['category_id' => $category->id, 'account_id' => $account->id, 'amount' => '750', 'expense_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'November rent', 'payee' => 'Landlord'])
            ->assertHasNoFormErrors()
            ->assertNotified('Expense recorded');

        $expense = Expense::sole();
        $this->assertSame('250.00', $this->balance($account->name));

        Livewire::test(ListExpenses::class)
            ->assertCanSeeTableRecords([$expense])
            ->callTableAction('void', $expense, ['reason' => 'Paid from the owner\'s pocket'])
            ->assertNotified('Expense voided')
            ->assertTableActionHidden('void', $expense);

        $this->assertSame('1000.00', $this->balance($account->name));

        // An accountant may record and void; a user with no finance role sees no screen.
        $this->actingAs($this->staff('accountant'));
        Livewire::test(ListExpenses::class)->assertActionVisible('record');
    }

    public function test_commission_rates_are_set_and_paid_from_the_screen(): void
    {
        $doctor = $this->doctor('Dr. Karim', null);
        $mri = $this->service('MRI', '8000');

        Livewire::test(ListCommission::class)
            ->assertSee('Not set')
            ->assertTableActionHidden('pay', $doctor)
            ->callTableAction('rates', $doctor, ['kind' => 'percent', 'value' => '150'])
            ->assertNotified('Not done');

        Livewire::test(ListCommission::class)
            ->callTableAction('rates', $doctor, [
                'kind' => 'percent', 'value' => '10',
                'overrides' => [['service_id' => $mri->id, 'kind' => 'fixed', 'value' => '500']],
            ])
            ->assertNotified('Rates saved');

        $this->assertSame(2, CommissionRule::where('referral_doctor_id', $doctor->id)->count());

        $this->pay($this->referred($doctor, [$mri, $this->service('CBC', '400')]), '8400');
        $account = Account::firstOrFail();

        Livewire::test(ListCommission::class)
            ->assertSee('10% (+1 special)')
            ->assertSee('540.00')
            ->assertTableActionVisible('pay', $doctor)
            ->callTableAction('pay', $doctor, ['amount' => '600', 'account_id' => $account->id])
            ->assertNotified('Not done');

        Livewire::test(ListCommission::class)
            ->callTableAction('pay', $doctor, ['amount' => '540', 'account_id' => $account->id, 'note' => 'November'])
            ->assertNotified('Commission paid');

        $this->assertSame('0.00', app(Commission::class)->balance($doctor)->toDecimal());
        $this->signIn($this->ownerUser)->get(self::BASE.'/finance/payouts')->assertOk()->assertSee('Dr. Karim')->assertSee('540.00');
    }

    public function test_the_profit_report_follows_its_dates(): void
    {
        $this->pay($this->order(['1000']), '1000');

        Livewire::test(ProfitReportPage::class)
            ->assertSee('Collected from patients')
            ->assertSee('1,000.00')
            ->set('from', now('Asia/Dhaka')->subDays(10)->toDateString())
            ->set('to', now('Asia/Dhaka')->subDays(5)->toDateString())
            ->assertSee('Nothing collected in this period.')
            ->set('to', now('Asia/Dhaka')->subDays(20)->toDateString())
            ->assertSee('The end date is before the start date.')
            ->set('from', '')
            ->assertSee('Enter both dates.');
    }

    public function test_the_dashboard_shows_account_balances(): void
    {
        $this->openCash();
        $order = $this->order(['1000']);
        $this->pay($order, '600', PaymentMethod::Cash);
        $this->pay($order, '400', PaymentMethod::Bkash);

        Livewire::test(BalancesWidget::class)
            ->assertSee('All accounts')
            ->assertSee('1,000.00')
            ->assertSee('Cash')
            ->assertSee('600.00');
    }
}
