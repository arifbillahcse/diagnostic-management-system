<?php

declare(strict_types=1);

namespace Modules\People\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Expense;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\People\Actions\ManageAdvances;
use Modules\People\Actions\ManageStaff;
use Modules\People\Actions\RunPayroll;
use Modules\People\Models\SalaryLine;
use Modules\People\Models\SalaryRun;
use Modules\People\Models\Staff;
use Modules\People\Support\AdvanceBook;
use Modules\People\Support\PeopleException;

class PayrollTest extends PeopleTestCase
{
    private function run_(string $month = '2026-10'): SalaryRun
    {
        return app(RunPayroll::class)->start($this->branch, $month);
    }

    private function line(SalaryRun $run, Staff $staff): SalaryLine
    {
        return $run->lines()->where('staff_id', $staff->id)->firstOrFail();
    }

    private function cashAccount(): Account
    {
        app(ModuleManager::class)->enable('finance');

        return Account::firstOrCreate(['name' => 'Cash box'], ['type' => PaymentMethod::Cash, 'is_default' => false, 'is_active' => true]);
    }

    public function test_a_run_pays_the_full_salary_for_a_full_month_and_a_share_for_part_of_one(): void
    {
        $karim = $this->person('Karim Mia', '15000', '2026-01-01');
        $new = $this->person('New Joiner', '31000', '2026-10-21'); // 11 of 31 days
        $this->mark('2026-10-05', [$karim->id => 'absent']);

        $run = $this->run_();

        $this->assertSame('draft', $run->status);
        $this->assertSame('SAL-2611-001', $run->run_no); // numbered by the day it was made, like other documents
        $k = $this->line($run, $karim);
        $this->assertSame('15000.00', $k->gross->toDecimal(), 'An absence is not deducted by itself');
        $this->assertSame(1, $k->days_absent);
        $this->assertSame('11000.00', $this->line($run, $new)->gross->toDecimal());
        $this->assertSame('26000.00', $run->total_net->toDecimal());
    }

    public function test_the_salary_that_applied_in_the_month_is_used(): void
    {
        $karim = $this->person('Karim Mia', '15000', '2026-01-01');
        app(ManageStaff::class)->changeSalary($karim, '18000', '2026-11-01', 'Increment');

        $this->assertSame('15000.00', $this->line($this->run_('2026-10'), $karim)->gross->toDecimal());
        $this->assertSame('18000.00', $this->line($this->run_('2026-11'), $karim)->gross->toDecimal());
    }

    public function test_one_live_run_per_branch_and_month_and_no_future_month(): void
    {
        $this->person();
        $run = $this->run_();

        foreach (['2026-10' => 'already has', '2026-12' => 'future', '2026-13' => 'valid month'] as $month => $message) {
            try {
                $this->run_($month);
                $this->fail("Started {$month}");
            } catch (PeopleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        app(RunPayroll::class)->cancel($run, 'Started too early');
        $this->assertSame('draft', $this->run_()->status, 'A cancelled run frees the month');
    }

    public function test_deductions_need_a_reason_and_cannot_exceed_the_salary(): void
    {
        $karim = $this->person('Karim Mia', '15000');
        $run = $this->run_();
        $line = $this->line($run, $karim);

        foreach ([['500', null, '0', 'Say why'], ['15000.01', 'Too much', '0', 'more than the salary'], ['5.555', 'x', '0', 'amount such as']] as [$d, $n, $r, $message]) {
            try {
                app(RunPayroll::class)->setLine($line, $d, $n, $r);
                $this->fail("Accepted {$d}");
            } catch (PeopleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        app(RunPayroll::class)->setLine($line, '1500', '3 days absent', '0');
        $run->refresh();
        $this->assertSame('13500.00', $this->line($run, $karim)->net->toDecimal());
        $this->assertSame('1500.00', $run->total_deductions->toDecimal());
        $this->assertSame('13500.00', $run->total_net->toDecimal());
        $this->assertTrue(AuditLog::where('action', 'salary_run.line_changed')->exists());

        // A refresh keeps the typed deduction.
        app(RunPayroll::class)->refresh($run);
        $this->assertSame('1500.00', $this->line($run, $karim)->deduction->toDecimal());
    }

    public function test_advances_are_taken_back_up_to_what_is_owed(): void
    {
        $karim = $this->person('Karim Mia', '15000');
        app(ManageAdvances::class)->give($karim, '4000', '2026-10-10', 'Family need');

        $run = $this->run_();
        $line = $this->line($run, $karim);
        $this->assertSame('4000.00', $line->advance_recovery->toDecimal(), 'Starts at what is owed');
        $this->assertSame('4000.00', app(AdvanceBook::class)->outstanding($karim->id)->toDecimal(), 'Not taken back until approved');

        try {
            app(RunPayroll::class)->setLine($line, '0', null, '4000.01');
            $this->fail('Took back more than owed');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('4,000.00', $e->getMessage());
        }

        app(RunPayroll::class)->setLine($line, '0', null, '2000');
        app(RunPayroll::class)->approve($run);

        $this->assertSame('2000.00', app(AdvanceBook::class)->outstanding($karim->id)->toDecimal());
        $this->assertSame('2000.00', $this->line($this->run_('2026-11'), $karim)->advance_recovery->toDecimal(), 'The rest is taken next month');

        app(RunPayroll::class)->cancel($run->fresh(), 'Wrong figures');
        $this->assertSame('4000.00', app(AdvanceBook::class)->outstanding($karim->id)->toDecimal(), 'Cancelling gives it back');
    }

    public function test_approving_rechecks_advances_taken_by_another_run(): void
    {
        $karim = $this->person('Karim Mia', '15000');
        app(ManageAdvances::class)->give($karim, '3000', '2026-10-10', 'Need');
        $october = $this->run_('2026-10');
        $november = $this->run_('2026-11');

        app(RunPayroll::class)->approve($october);

        $this->expectExceptionMessage('owes less advance');
        app(RunPayroll::class)->approve($november);
    }

    public function test_the_state_machine_and_what_each_state_locks(): void
    {
        $karim = $this->person('Karim Mia', '15000');
        $run = $this->run_();
        app(RunPayroll::class)->approve($run);
        $run->refresh();

        $this->assertSame('approved', $run->status);

        foreach ([
            fn () => app(RunPayroll::class)->refresh($run),
            fn () => app(RunPayroll::class)->setLine($this->line($run, $karim), '1', 'x', '0'),
            fn () => app(RunPayroll::class)->approve($run),
            fn () => $this->mark('2026-10-12', [$karim->id => 'present']),
            fn () => app(ManageStaff::class)->changeSalary($karim, '16000', '2026-10-15', 'Raise'),
            fn () => app(ManageStaff::class)->markLeft($karim, '2026-10-20', 'Resigned'),
        ] as $try) {
            try {
                $try();
                $this->fail('An approved month changed');
            } catch (PeopleException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->line($run, $karim)->update(['net' => '1']);
            $this->fail('An approved line changed');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        app(RunPayroll::class)->pay($run, '2026-11-05');
        $run->refresh();
        $this->assertSame('paid', $run->status);
        $this->assertNull($run->expense_id, 'Finance is off');

        $this->expectExceptionMessage('cannot be cancelled');
        app(RunPayroll::class)->cancel($run, 'Too late');
    }

    public function test_with_finance_on_advances_and_salaries_become_expenses(): void
    {
        $cash = $this->cashAccount();
        $karim = $this->person('Karim Mia', '15000');

        try {
            app(ManageAdvances::class)->give($karim, '2000', '2026-10-10', 'Need');
            $this->fail('Advance without an account');
        } catch (PeopleException $e) {
            $this->assertStringContainsString('account', $e->getMessage());
        }

        $advance = app(ManageAdvances::class)->give($karim, '2000', '2026-10-10', 'Need', $cash->id);
        $this->assertSame('2000.00', Expense::findOrFail($advance->expense_id)->amount->toDecimal());

        $run = $this->run_();
        app(RunPayroll::class)->approve($run);
        app(RunPayroll::class)->pay($run->fresh(), '2026-11-05', $cash->id);

        $expense = Expense::findOrFail($run->fresh()->expense_id);
        $this->assertSame('13000.00', $expense->amount->toDecimal(), 'Net pay: the advance was already an expense');
        $this->assertSame('Salaries', $expense->category->name);
    }

    public function test_an_advance_is_voided_with_its_expense_only_while_nothing_was_taken_back(): void
    {
        $cash = $this->cashAccount();
        $karim = $this->person('Karim Mia', '15000');
        $first = app(ManageAdvances::class)->give($karim, '1000', '2026-10-10', 'Need', $cash->id);

        app(ManageAdvances::class)->void($first, 'Entered twice');
        $this->assertTrue($first->fresh()->isVoid());
        $this->assertNotNull(Expense::findOrFail($first->expense_id)->voided_at);

        $second = app(ManageAdvances::class)->give($karim, '1000', '2026-10-10', 'Need', $cash->id);
        app(RunPayroll::class)->approve($this->run_());

        $this->expectExceptionMessage('already been taken back');
        app(ManageAdvances::class)->void($second, 'Late');
    }

    public function test_permissions_split_preparing_approving_and_paying(): void
    {
        $this->person();
        $this->actingAs($this->staff('accountant'));
        $run = $this->run_();

        try {
            app(RunPayroll::class)->approve($run);
            $this->fail('An accountant approved');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->staff('admin'));
        app(RunPayroll::class)->approve($run);

        $this->actingAs($this->staff('accountant'));
        app(RunPayroll::class)->pay($run->fresh(), '2026-11-05');

        $this->actingAs($this->staff('receptionist'));
        $this->expectException(AuthorizationException::class);
        app(RunPayroll::class)->start($this->branch, '2026-09');
    }

    public function test_an_advance_given_after_the_draft_joins_it_on_refresh(): void
    {
        $karim = $this->person('Karim Mia', '15000');
        $run = $this->run_();
        $this->assertSame([], app(RunPayroll::class)->newAdvances($run));

        $this->travel(2)->seconds();
        app(ManageAdvances::class)->give($karim, '1500', '2026-11-10', 'Need');

        $this->assertSame(['Karim Mia'], app(RunPayroll::class)->newAdvances($run));
        app(RunPayroll::class)->refresh($run);
        $this->assertSame('1500.00', $this->line($run, $karim)->advance_recovery->toDecimal());
        $this->assertSame([], app(RunPayroll::class)->newAdvances($run->fresh()));
    }
}
