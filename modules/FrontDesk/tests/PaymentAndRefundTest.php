<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Money\Money;
use App\Core\Settings\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use LogicException;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Events\PaymentReceived;
use Modules\FrontDesk\Events\RefundIssued;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;

final class PaymentAndRefundTest extends FrontDeskTestCase
{
    private function requestRefund($order, string $amount, PaymentMethod $method = PaymentMethod::Bkash, string $reason = 'Test not done'): Refund
    {
        return app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal($amount), $method, $reason);
    }

    public function test_partial_payments_reduce_the_due_until_the_invoice_is_paid(): void
    {
        Event::fake([PaymentReceived::class]);
        $order = $this->order(['1000']);

        $first = $this->pay($order, '300');
        $this->assertMatchesRegularExpression('/^MR-\d{4}-00001$/', $first->receipt_no);
        $this->assertInvoice($order, '1000.00', '300.00', '0.00', '700.00', 'partially_paid');

        $this->pay($order, '699.99');
        $this->assertInvoice($order, '1000.00', '999.99', '0.00', '0.01', 'partially_paid');

        $this->pay($order, '0.01');
        $this->assertInvoice($order, '1000.00', '1000.00', '0.00', '0.00', 'paid');

        $entry = AuditLog::where('action', 'payment.recorded')->orderBy('id')->first();
        $this->assertEquals(['receipt_no' => $first->receipt_no, 'amount' => '300.00', 'due_after' => '700.00'], array_intersect_key($entry->after, array_flip(['receipt_no', 'amount', 'due_after'])));
        Event::assertDispatchedTimes(PaymentReceived::class, 3);
    }

    public function test_a_payment_cannot_be_zero_negative_or_more_than_the_due(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '400');

        foreach (['0', '-50', '600.01'] as $amount) {
            try {
                $this->pay($order, $amount);
                $this->fail("A payment of {$amount} should be refused.");
            } catch (FrontDeskException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Payment::count());
        $this->assertInvoice($order, '1000.00', '400.00', '0.00', '600.00', 'partially_paid');
    }

    public function test_a_repeated_request_with_the_same_key_takes_the_money_once(): void
    {
        $order = $this->order(['1000']);

        $first = $this->pay($order, '500', PaymentMethod::Bkash, 'txn-abc-123');
        $again = $this->pay($order, '500', PaymentMethod::Bkash, 'txn-abc-123');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Payment::count());
        $this->assertInvoice($order, '1000.00', '500.00', '0.00', '500.00', 'partially_paid');

        // The same key for a different amount is a mistake, not a retry.
        $this->expectException(FrontDeskException::class);
        $this->pay($order, '200', PaymentMethod::Bkash, 'txn-abc-123');
    }

    public function test_payments_cannot_be_edited_or_deleted(): void
    {
        $payment = $this->pay($this->order(['500']), '500');

        foreach ([fn () => $payment->update(['amount' => '1']), fn () => $payment->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Payments must be immutable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('500.00', Payment::firstOrFail()->amount->toDecimal());
    }

    public function test_cash_needs_an_open_cash_session_and_other_methods_do_not(): void
    {
        $order = $this->order(['1000']);

        try {
            $this->pay($order, '100', PaymentMethod::Cash);
            $this->fail('Cash without an open session should be refused.');
        } catch (FrontDeskException $e) {
            $this->assertSame('Open your cash session before taking or giving cash.', $e->getMessage());
        }

        $this->assertNull($this->pay($order, '100', PaymentMethod::Card)->cash_session_id);

        $session = $this->openCash('200');
        $this->assertSame($session->id, $this->pay($order, '100', PaymentMethod::Cash)->cash_session_id);
    }

    public function test_recording_a_payment_needs_the_permission(): void
    {
        $order = $this->order(['1000']);
        $this->actingAs($this->staff('lab_technician'));

        $this->expectException(AuthorizationException::class);
        $this->pay($order, '100');
    }

    public function test_a_refund_changes_nothing_until_a_second_person_approves_it(): void
    {
        Event::fake([RefundIssued::class]);
        $receptionist = $this->staff('receptionist');
        $accountant = $this->staff('accountant');

        $this->actingAs($receptionist);
        $order = $this->order(['1000']);
        $this->pay($order, '1000');

        $refund = $this->requestRefund($order, '400');
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->refund_no);
        $this->assertInvoice($order, '1000.00', '1000.00', '0.00', '0.00', 'paid');

        try {
            app(DecideRefund::class)->approve($refund);
            $this->fail('A receptionist cannot approve refunds.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($accountant);
        $approved = app(DecideRefund::class)->approve($refund, 'Checked with lab');

        $this->assertSame(RefundStatus::Approved, $approved->status);
        $this->assertMatchesRegularExpression('/^RF-\d{4}-00001$/', $approved->refund_no);
        $this->assertSame($accountant->id, $approved->decided_by);
        $this->assertInvoice($order, '1000.00', '1000.00', '400.00', '400.00', 'partially_paid');
        $this->assertSame(1, AuditLog::where('action', 'refund.requested')->count());
        $this->assertSame(1, AuditLog::where('action', 'refund.approved')->count());
        Event::assertDispatchedTimes(RefundIssued::class, 1);

        $this->expectExceptionMessage('This refund has already been approved.');
        app(DecideRefund::class)->approve($refund);
    }

    public function test_the_requester_cannot_approve_their_own_refund_unless_the_clinic_allows_it(): void
    {
        $accountant = $this->staff('accountant');
        $this->actingAs($accountant);
        $receptionistOrder = fn () => tap($this->orderAs($this->staff('receptionist'), ['1000']), fn () => $this->actingAs($accountant));

        $order = $receptionistOrder();
        $this->pay($order, '1000');
        $refund = $this->requestRefund($order, '100');

        try {
            app(DecideRefund::class)->approve($refund);
            $this->fail('Self-approval should be refused by default.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('different person', $e->getMessage());
        }
        $this->assertInvoice($order, '1000.00', '1000.00', '0.00', '0.00', 'paid');

        // The clinic switches the rule off: the request is approved at once.
        app(Settings::class)->set(Permissions::SETTING_REFUND_APPROVAL, false);

        $second = $this->requestRefund($order, '50');
        $this->assertSame(RefundStatus::Approved, $second->status);
        $this->assertSame($accountant->id, $second->decided_by);

        // A receptionist still cannot approve; their request stays pending.
        $this->actingAs($this->staff('receptionist'));
        $third = $this->requestRefund($order, '10');
        $this->assertSame(RefundStatus::Pending, $third->status);
    }

    private function orderAs($user, array $prices)
    {
        $this->actingAs($user);

        return $this->order($prices);
    }

    public function test_refunds_cannot_exceed_what_was_paid_including_requests_still_waiting(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '600');

        $this->requestRefund($order, '400');

        try {
            $this->requestRefund($order, '200.01');
            $this->fail('Only 200 is left to request.');
        } catch (FrontDeskException $e) {
            $this->assertSame('Only 200.00 can be refunded on this invoice.', $e->getMessage());
        }

        $this->requestRefund($order, '200');

        foreach (['0', '-5'] as $amount) {
            try {
                $this->requestRefund($order, $amount);
                $this->fail('Refunds must be positive.');
            } catch (FrontDeskException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectExceptionMessage('A reason is required for a refund.');
        $this->requestRefund($order, '1', PaymentMethod::Bkash, '  ');
    }

    public function test_a_rejected_refund_moves_no_money_and_frees_the_amount(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '500');
        $refund = $this->requestRefund($order, '500');

        $this->actingAs($this->staff('accountant'));

        try {
            app(DecideRefund::class)->reject($refund, '');
            $this->fail('Rejecting needs a reason.');
        } catch (FrontDeskException) {
            $this->addToAssertionCount(1);
        }

        $rejected = app(DecideRefund::class)->reject($refund, 'Test was performed');

        $this->assertSame(RefundStatus::Rejected, $rejected->status);
        $this->assertNull($rejected->refund_no);
        $this->assertInvoice($order, '1000.00', '500.00', '0.00', '500.00', 'partially_paid');

        $this->assertSame(RefundStatus::Pending, $this->requestRefund($order, '500')->status);
    }

    public function test_a_cash_refund_comes_out_of_the_approvers_cash_session(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '1000');
        $refund = $this->requestRefund($order, '300', PaymentMethod::Cash);

        $accountant = $this->staff('accountant');
        $this->actingAs($accountant);

        try {
            app(DecideRefund::class)->approve($refund);
            $this->fail('A cash refund needs the approver to have an open session.');
        } catch (FrontDeskException) {
            $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
        }

        $session = $this->openCash('500');
        $approved = app(DecideRefund::class)->approve($refund);

        $this->assertSame($session->id, $approved->cash_session_id);
    }
}
