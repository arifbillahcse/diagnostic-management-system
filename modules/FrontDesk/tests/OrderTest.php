<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\LineKind;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Events\InvoiceIssued;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\InvoiceLine;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Support\FrontDeskException;

final class OrderTest extends FrontDeskTestCase
{
    public function test_an_order_creates_items_an_invoice_and_numbers(): void
    {
        Event::fake([OrderPlaced::class, InvoiceIssued::class]);
        $doctor = ReferralDoctor::create(['name' => 'Dr. Karim']);

        $order = $this->order(['500', '1250.50'], ['referral_doctor_id' => $doctor->id, 'notes' => ' Fasting sample ']);

        $this->assertMatchesRegularExpression('/^ORD-MAIN-\d{4}-00001$/', $order->order_no);
        $this->assertMatchesRegularExpression('/^INV-MAIN-\d{4}-00001$/', $order->invoice->invoice_no);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('Fasting sample', $order->notes);
        $this->assertSame($doctor->id, $order->referral_doctor_id);
        $this->assertSame($this->ownerUser->id, $order->created_by);

        $this->assertSame(['500.00', '1250.50'], $order->items->map(fn ($i) => $i->net_amount->toDecimal())->all());
        $this->assertSame(2, $order->invoice->lines()->where('kind', LineKind::Charge->value)->count());
        $this->assertInvoice($order, '1750.50', '0.00', '0.00', '1750.50', 'issued');

        $entry = AuditLog::where('action', 'order.placed')->sole();
        $this->assertSame('1750.50', $entry->after['total']);
        Event::assertDispatched(OrderPlaced::class);
        Event::assertDispatched(InvoiceIssued::class);

        $second = $this->order(['100']);
        $this->assertStringEndsWith('-00002', $second->order_no);
        $this->assertStringEndsWith('-00002', $second->invoice->invoice_no);
    }

    public function test_prices_and_names_are_copied_so_catalog_edits_do_not_change_old_orders(): void
    {
        $service = $this->service('Complete blood count', '400');
        $order = $this->order([$service]);

        $service->update(['name' => 'CBC (new name)', 'price' => '999']);

        $item = $order->items()->first();
        $this->assertSame('Complete blood count', $item->service_name);
        $this->assertSame('400.00', $item->unit_price->toDecimal());
        $this->assertInvoice($order, '400.00', '0.00', '0.00', '400.00', 'issued');
    }

    public function test_a_percent_discount_is_shared_across_items_to_the_poisha(): void
    {
        // 10% of 999.99 is 99.999, which rounds to 100.00.
        $order = $this->order(['333.33', '333.33', '333.33'], ['discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Staff family']);

        $shares = $order->items->map(fn ($i) => $i->discount_amount->toDecimal())->all();
        $this->assertSame(['33.34', '33.33', '33.33'], $shares, 'the spare poisha goes to the first item');
        $this->assertSame(['299.99', '300.00', '300.00'], $order->items->map(fn ($i) => $i->net_amount->toDecimal())->all());

        $invoice = $this->invoice($order);
        $this->assertSame('999.99', $invoice->subtotal->toDecimal());
        $this->assertSame('100.00', $invoice->discount_total->toDecimal());
        $this->assertSame('899.99', $invoice->total->toDecimal());
        $this->assertSame('Staff family', $invoice->discount_reason);
        $this->assertSame($this->ownerUser->id, $invoice->discount_by);
        $this->assertSame('-100.00', $invoice->lines()->where('kind', 'discount')->sole()->amount->toDecimal());
    }

    public function test_a_fixed_discount_works_and_cannot_exceed_the_order(): void
    {
        $order = $this->order(['700', '300'], ['discount_type' => 'amount', 'discount_value' => '150', 'discount_reason' => 'Camp offer']);

        $this->assertSame(['105.00', '45.00'], $order->items->map(fn ($i) => $i->discount_amount->toDecimal())->all());
        $this->assertInvoice($order, '850.00', '0.00', '0.00', '850.00', 'issued');

        $this->expectException(FrontDeskException::class);
        $this->order(['100'], ['discount_type' => 'amount', 'discount_value' => '100.01', 'discount_reason' => 'Too much']);
    }

    public function test_a_discount_needs_a_reason(): void
    {
        $this->expectExceptionMessage('A reason is required for a discount.');
        $this->order(['500'], ['discount_type' => 'percent', 'discount_value' => '5']);
    }

    public function test_discounts_respect_the_role_limit(): void
    {
        $this->actingAs($this->staff('receptionist'));

        $ok = $this->order(['1000'], ['discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Regular patient']);
        $this->assertInvoice($ok, '900.00', '0.00', '0.00', '900.00', 'issued');

        try {
            $this->order(['1000'], ['discount_type' => 'amount', 'discount_value' => '100.01', 'discount_reason' => 'Regular patient']);
            $this->fail('A receptionist may not go above 10%.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('above your limit of 10%', $e->getMessage());
        }

        // Admin holds the override permission.
        $this->actingAs($this->staff('admin'));
        $big = $this->order(['1000'], ['discount_type' => 'percent', 'discount_value' => '60', 'discount_reason' => 'Owner approved']);
        $this->assertInvoice($big, '400.00', '0.00', '0.00', '400.00', 'issued');
    }

    public function test_a_user_without_the_discount_permission_cannot_discount(): void
    {
        $this->actingAs($this->staff('lab_technician'));

        $this->expectException(AuthorizationException::class);
        $this->order(['1000']);
    }

    public function test_a_package_is_billed_once_and_carries_its_tests(): void
    {
        $cbc = $this->service('CBC', '400');
        $esr = $this->service('ESR', '200');
        $package = $this->package('Fever panel', '500', $cbc, $esr);

        $order = $this->order([$package]);

        $this->assertInvoice($order, '500.00', '0.00', '0.00', '500.00', 'issued');
        $this->assertSame(1, $this->invoice($order)->lines()->count());

        $items = $order->items;
        $this->assertSame(['Fever panel', 'CBC', 'ESR'], $items->pluck('service_name')->all());
        $this->assertSame(['500.00', '0.00', '0.00'], $items->map(fn ($i) => $i->unit_price->toDecimal())->all());
        $this->assertSame([null, $items[0]->id, $items[0]->id], $items->pluck('package_item_of')->all());
    }

    public function test_orders_that_make_no_sense_are_refused(): void
    {
        $cbc = $this->service('CBC', '400');
        $esr = $this->service('ESR', '200');
        $fever = $this->package('Fever panel', '500', $cbc, $esr);
        $basic = $this->package('Basic panel', '350', $cbc);
        $empty = $this->package('Empty panel', '100');
        $retired = $this->service('Old test', '100');
        $retired->update(['is_active' => false]);

        $place = fn (array $services) => app(PlaceOrder::class)->handle($this->patient(), $this->branch, array_map(fn ($s) => $s->id, $services));

        foreach ([
            'nothing chosen' => [],
            'same test twice' => [$cbc, $cbc],
            'test already in the package' => [$fever, $cbc],
            'two packages sharing a test' => [$fever, $basic],
            'package with no tests' => [$empty],
            'inactive service' => [$retired],
        ] as $case => $services) {
            try {
                $place($services);
                $this->fail("Should have been refused: {$case}");
            } catch (FrontDeskException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::connection('clinic')->table('counters')->where('counter_key', 'order')->value('next_value') ?? 0, 'no order number was used up');
    }

    public function test_invoice_lines_cannot_be_edited_or_deleted(): void
    {
        $order = $this->order(['500']);
        $line = InvoiceLine::firstOrFail();

        foreach ([fn () => $line->update(['amount' => '1']), fn () => $line->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Invoice lines must be immutable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('500.00', InvoiceLine::firstOrFail()->amount->toDecimal());
        $this->assertSame(ItemStatus::Active, $order->items()->first()->status);
    }

    public function test_creating_orders_needs_the_permission(): void
    {
        $this->actingAs($this->staff('accountant'));

        $this->expectException(AuthorizationException::class);
        $this->order(['500']);
    }
}
