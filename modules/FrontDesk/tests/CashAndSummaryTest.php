<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Actions\ManageCashSession;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\CashSessionStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Support\DailyFigures;
use Modules\FrontDesk\Support\FrontDeskException;

final class CashAndSummaryTest extends FrontDeskTestCase
{
    private function cash(): ManageCashSession
    {
        return app(ManageCashSession::class);
    }

    public function test_a_user_has_one_open_cash_session_at_a_time(): void
    {
        $session = $this->openCash('500');

        $this->assertSame(CashSessionStatus::Open, $session->status);
        $this->assertSame('500.00', $session->opening_float->toDecimal());

        try {
            $this->openCash('100');
            $this->fail('A second open session should be refused.');
        } catch (FrontDeskException) {
            $this->assertSame(1, CashSession::count());
        }

        // Another user has their own.
        $this->actingAs($this->staff('receptionist'));
        $this->assertNotSame($session->id, $this->openCash('0')->id);
    }

    public function test_closing_compares_the_count_with_cash_in_minus_cash_out(): void
    {
        $session = $this->openCash('500');
        $order = $this->order(['1000', '300']);

        $this->pay($order, '700', PaymentMethod::Cash);
        $this->pay($order, '400', PaymentMethod::Bkash); // not cash: not in the drawer
        $this->pay($order, '200', PaymentMethod::Cash);

        // A cash refund approved by a second person comes from that
        // person's session, not this one.
        $refund = app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal('100'), PaymentMethod::Cash, 'Overcharged');
        $accountant = $this->staff('accountant');
        $this->actingAs($accountant);
        $accountantSession = $this->openCash('1000');
        app(DecideRefund::class)->approve($refund);
        $this->cash()->close($accountantSession, Money::fromDecimal('900'));
        $this->assertSame('900.00', $accountantSession->fresh()->expected_cash->toDecimal());

        $this->actingAs($this->ownerUser);
        $closed = $this->cash()->close($session, Money::fromDecimal('1400'));

        $this->assertSame(CashSessionStatus::Closed, $closed->status);
        $this->assertSame('1400.00', $closed->expected_cash->toDecimal(), '500 float + 700 + 200 cash');
        $this->assertSame('0.00', $closed->difference->toDecimal());
        $this->assertSame('1400.00', AuditLog::where('action', 'cash_session.closed')->latest('id')->first()->after['counted_cash']);
    }

    public function test_a_difference_must_be_explained_and_is_recorded(): void
    {
        $session = $this->openCash('500');
        $this->pay($this->order(['300']), '300', PaymentMethod::Cash);

        try {
            $this->cash()->close($session, Money::fromDecimal('780'));
            $this->fail('A shortage without a note should be refused.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('differs from the expected 800.00 by -20.00', $e->getMessage());
        }

        $closed = $this->cash()->close($session, Money::fromDecimal('780'), 'Gave wrong change');

        $this->assertSame('-20.00', $closed->difference->toDecimal());
        $this->assertSame('Gave wrong change', $closed->notes);
    }

    public function test_a_closed_session_stays_closed_and_takes_no_more_cash(): void
    {
        $session = $this->openCash('0');
        $this->cash()->close($session, Money::zero());

        try {
            $this->cash()->close($session, Money::zero());
            $this->fail('Closing twice should be refused.');
        } catch (FrontDeskException) {
            $this->addToAssertionCount(1);
        }

        $this->expectExceptionMessage('Open your cash session before taking or giving cash.');
        $this->pay($this->order(['100']), '100', PaymentMethod::Cash);
    }

    public function test_only_a_supervisor_can_close_someone_elses_session(): void
    {
        $receptionist = $this->staff('receptionist');
        $this->actingAs($receptionist);
        $session = $this->openCash('100');

        $this->actingAs($this->staff('receptionist'));
        try {
            $this->cash()->close($session, Money::fromDecimal('100'));
            $this->fail('Another receptionist cannot close this session.');
        } catch (AuthorizationException) {
            $this->assertTrue($session->fresh()->isOpen());
        }

        $accountant = $this->staff('accountant');
        $this->actingAs($accountant);
        $closed = $this->cash()->close($session, Money::fromDecimal('100'));

        $this->assertSame($accountant->id, $closed->closed_by);
    }

    public function test_daily_figures_add_up_and_can_be_rebuilt_safely(): void
    {
        $figures = app(DailyFigures::class);
        $today = $figures->today();

        $a = $this->order(['1000', '500']);
        $b = $this->order(['300']);
        $c = $this->order(['200']);
        $this->pay($a, '1500');
        $this->pay($b, '100');
        app(AdjustOrder::class)->cancelItem($a->items[1], 'Not done');   // billed 2000 - 500
        app(AdjustOrder::class)->cancelOrder($c, 'Wrong patient');       // void: leaves billed

        $refund = app(RequestRefund::class)->handle($this->invoice($a), Money::fromDecimal('500'), PaymentMethod::Bkash, 'Cancelled test');
        $this->actingAs($this->staff('accountant'));
        app(DecideRefund::class)->approve($refund);

        $day = $figures->for($today, $this->branch->id);

        $this->assertSame(2, $day['orders_count'], 'the cancelled order is not counted');
        $this->assertSame(2, $day['patients_count']);
        $this->assertSame('1300.00', $day['billed_total']->toDecimal());
        $this->assertSame('1600.00', $day['collected_total']->toDecimal());
        $this->assertSame('500.00', $day['refunded_total']->toDecimal());
        $this->assertSame('200.00', $day['due_total']->toDecimal());

        $this->assertSame(0, $figures->for($today->subDay(), $this->branch->id)['orders_count']);

        $this->artisan('frontdesk:summarize')->assertSuccessful();
        $this->artisan('frontdesk:summarize')->assertSuccessful();

        $rows = DB::connection('clinic')->table('fd_daily_summaries')->where('summary_date', $today->toDateString())->get();
        $this->assertCount(1, $rows, 'rebuilding replaces the row');
        $this->assertSame('1300.00', number_format((float) $rows[0]->billed_total, 2, '.', ''));
    }

    public function test_a_day_is_the_clinics_local_day(): void
    {
        $figures = app(DailyFigures::class);

        // 19:30 UTC is 01:30 the next day in Dhaka.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 19:30:00', 'UTC'));
        $this->order(['100']);
        $this->travelBack();

        $this->assertSame(0, $figures->for(CarbonImmutable::parse('2026-11-10'), $this->branch->id)['orders_count']);
        $this->assertSame(1, $figures->for(CarbonImmutable::parse('2026-11-11'), $this->branch->id)['orders_count']);
    }

    public function test_the_invoice_check_finds_a_stored_total_that_was_tampered_with(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '400');

        $this->artisan('frontdesk:check-invoices')->expectsOutputToContain('1 invoice(s) checked, 0 with differences.')->assertSuccessful();

        DB::connection('clinic')->table('fd_invoices')->update(['due_amount' => '0.00', 'status' => 'paid']);

        $this->artisan('frontdesk:check-invoices')
            ->expectsOutputToContain('due_amount: stored 0.00, calculated 600.00')
            ->assertFailed();
    }

    public function test_switching_the_module_off_denies_its_permissions_and_keeps_the_data(): void
    {
        $order = $this->order(['1000']);
        $modules = app(ModuleManager::class);

        $modules->disable('frontdesk');

        try {
            $this->pay($order, '100');
            $this->fail('A disabled module must not take payments.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $modules->enable('frontdesk');

        $this->pay($order, '100');
        $this->assertInvoice($order, '1000.00', '100.00', '0.00', '900.00', 'partially_paid');
    }
}
