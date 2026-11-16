<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Support\FrontDeskException;

final class AdjustmentTest extends FrontDeskTestCase
{
    private function adjust(): AdjustOrder
    {
        return app(AdjustOrder::class);
    }

    public function test_cancelling_a_test_adds_a_negative_line_and_keeps_the_original(): void
    {
        Event::fake([OrderItemCancelled::class]);
        $order = $this->order(['600', '400']);

        $this->adjust()->cancelItem($order->items[1], 'Patient changed mind');

        $lines = $this->invoice($order)->lines;
        $this->assertSame(['600.00', '400.00', '-400.00'], $lines->map(fn ($l) => $l->amount->toDecimal())->all());
        $this->assertSame(['charge', 'charge', 'adjustment'], $lines->map(fn ($l) => $l->kind->value)->all());
        $this->assertStringStartsWith('Cancelled: ', $lines[2]->description);
        $this->assertInvoice($order, '600.00', '0.00', '0.00', '600.00', 'issued');

        $item = $order->items()->get()[1];
        $this->assertSame(ItemStatus::Cancelled, $item->status);
        $this->assertSame('Patient changed mind', $item->cancel_reason);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('Patient changed mind', AuditLog::where('action', 'order.item_cancelled')->sole()->context['reason']);
        Event::assertDispatched(OrderItemCancelled::class);
    }

    public function test_cancelling_a_discounted_test_takes_back_only_its_net_amount(): void
    {
        $order = $this->order(['700', '300'], ['discount_type' => 'amount', 'discount_value' => '100', 'discount_reason' => 'Offer']);
        $this->assertInvoice($order, '900.00', '0.00', '0.00', '900.00', 'issued');

        // The 300 test carried 30 of the discount, so 270 comes off.
        $this->adjust()->cancelItem($order->items[1], 'Not needed');

        $this->assertInvoice($order, '630.00', '0.00', '0.00', '630.00', 'issued');
    }

    public function test_cancelling_after_payment_leaves_a_credit_that_a_refund_clears(): void
    {
        $order = $this->order(['600', '400']);
        $this->pay($order, '1000');

        $this->adjust()->cancelItem($order->items[1], 'Sample could not be taken');

        $this->assertInvoice($order, '600.00', '1000.00', '0.00', '-400.00', 'paid');
        $this->assertSame('400.00', $this->invoice($order)->credit()->toDecimal());

        $refund = app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal('400'), PaymentMethod::Bkash, 'Test cancelled');
        $this->actingAs($this->staff('accountant'));
        app(DecideRefund::class)->approve($refund);

        $this->assertInvoice($order, '600.00', '1000.00', '400.00', '0.00', 'paid');
    }

    public function test_cancelling_every_test_cancels_the_order_and_a_full_refund_closes_it(): void
    {
        Event::fake([OrderCancelled::class]);
        $order = $this->order(['500']);
        $this->pay($order, '500');

        $this->adjust()->cancelItem($order->items[0], 'Machine down');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertInvoice($order, '0.00', '500.00', '0.00', '-500.00', 'paid');
        Event::assertDispatched(OrderCancelled::class);

        $refund = app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal('500'), PaymentMethod::Bkash, 'Machine down');
        $this->actingAs($this->staff('accountant'));
        app(DecideRefund::class)->approve($refund);

        $this->assertInvoice($order, '0.00', '500.00', '500.00', '0.00', 'refunded');
    }

    public function test_a_package_is_cancelled_as_a_whole(): void
    {
        $cbc = $this->service('CBC', '400');
        $esr = $this->service('ESR', '200');
        $order = $this->order([$this->package('Fever panel', '500', $cbc, $esr), $this->service('Urine R/E', '150')]);

        try {
            $this->adjust()->cancelItem($order->items[1], 'Only this one');
            $this->fail('A test inside a package cannot be cancelled alone.');
        } catch (FrontDeskException) {
            $this->assertSame(ItemStatus::Active, $order->items()->get()[1]->status);
        }

        $this->adjust()->cancelItem($order->items[0], 'Doctor changed advice');

        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'active'], $order->items()->get()->map(fn ($i) => $i->status->value)->all());
        $this->assertInvoice($order, '150.00', '0.00', '0.00', '150.00', 'issued');
    }

    public function test_a_test_cannot_be_cancelled_twice_or_without_a_reason(): void
    {
        $order = $this->order(['600', '400']);

        try {
            $this->adjust()->cancelItem($order->items[1], ' ');
            $this->fail('A reason is required.');
        } catch (FrontDeskException) {
            $this->addToAssertionCount(1);
        }

        $this->adjust()->cancelItem($order->items[1], 'Once');

        $this->expectExceptionMessage('This test is already cancelled.');
        $this->adjust()->cancelItem($order->items[1], 'Twice');
    }

    public function test_adding_a_test_adds_a_positive_line_at_full_price(): void
    {
        $order = $this->order(['1000'], ['discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Offer']);
        $this->pay($order, '900');
        $this->assertInvoice($order, '900.00', '900.00', '0.00', '0.00', 'paid');

        $extra = $this->service('Blood glucose', '250');
        $item = $this->adjust()->addItem($order, $extra->id);

        $this->assertSame('250.00', $item->net_amount->toDecimal());
        $this->assertInvoice($order, '1150.00', '900.00', '0.00', '250.00', 'partially_paid');
        $this->assertSame('Added: Blood glucose', $this->invoice($order)->lines->last()->description);

        $this->expectException(FrontDeskException::class);
        $this->adjust()->addItem($order, $extra->id);
    }

    public function test_an_unpaid_order_can_be_cancelled_and_its_invoice_voided(): void
    {
        $order = $this->order(['600', '400']);

        $this->adjust()->cancelOrder($order, 'Entered for the wrong patient');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertInvoice($order, '1000.00', '0.00', '0.00', '0.00', 'void');
        $this->assertSame('Entered for the wrong patient', $this->invoice($order)->void_reason);
        $this->assertSame(0, $order->items()->where('status', 'active')->count());

        foreach ([
            fn () => $this->pay($order, '100'),
            fn () => $this->adjust()->addItem($order, $this->service('X', '10')->id),
            fn () => $this->adjust()->cancelOrder($order, 'Again'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A cancelled order accepts no further changes.');
            } catch (FrontDeskException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_order_with_money_received_cannot_simply_be_cancelled(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '1');

        try {
            $this->adjust()->cancelOrder($order, 'Mistake');
            $this->fail('Money has been received.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('Money has been received', $e->getMessage());
        }

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_adjustments_follow_the_permissions(): void
    {
        $order = $this->order(['600', '400']);
        $receptionist = $this->staff('receptionist');

        $this->actingAs($receptionist);

        try {
            $this->adjust()->cancelItem($order->items[0], 'No');
            $this->fail('A receptionist cannot adjust an issued invoice.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // But may cancel an order nobody has paid for.
        $this->adjust()->cancelOrder($order, 'Duplicate entry');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $this->actingAs($this->staff('lab_technician'));
        $this->expectException(AuthorizationException::class);
        $this->adjust()->cancelOrder($this->orderAsOwner(), 'No');
    }

    private function orderAsOwner()
    {
        $current = auth()->user();
        $this->actingAs($this->ownerUser);
        $order = $this->order(['100']);
        $this->actingAs($current);

        return $order;
    }
}
