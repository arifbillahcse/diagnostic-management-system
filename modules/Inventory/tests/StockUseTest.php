<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Modules\ModuleManager;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Models\Service;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Shortfall;
use Modules\Inventory\Models\Usage;
use Modules\Inventory\Support\StockLedger;
use Modules\Inventory\Support\StockUse;
use RuntimeException;

final class StockUseTest extends InventoryTestCase
{
    private Item $tubes;

    private Item $reagent;

    private Service $cbc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tubes = $this->item();
        $this->reagent = $this->item('CBCREAG', 'CBC diluent', true, 'ml');
        $this->cbc = $this->service('CBC', '400');
        Usage::create(['service_id' => $this->cbc->id, 'item_id' => $this->tubes->id, 'quantity' => '1']);
        Usage::create(['service_id' => $this->cbc->id, 'item_id' => $this->reagent->id, 'quantity' => '2.5']);
    }

    private function place(array $services): Order
    {
        return app(PlaceOrder::class)->handle($this->patient(), $this->branch, array_map(fn (Service $s): int => $s->id, $services));
    }

    private function stockUp(): void
    {
        $this->buy([
            ['item_id' => $this->tubes->id, 'quantity' => '10', 'unit_cost' => '3.00'],
            ['item_id' => $this->reagent->id, 'quantity' => '100', 'unit_cost' => '2.00', 'batch_no' => 'LATE', 'expires_on' => '2028-01-31'],
            ['item_id' => $this->reagent->id, 'quantity' => '4', 'unit_cost' => '2.00', 'batch_no' => 'SOON', 'expires_on' => '2027-03-31'],
        ]);
    }

    public function test_ordering_a_test_takes_its_stock_out(): void
    {
        $this->stockUp();

        $order = $this->place([$this->cbc]);

        $this->assertSame('9 @ 27.00', $this->stock($this->tubes));
        $this->assertSame('101.5 @ 203.00', $this->stock($this->reagent));

        $uses = Movement::where('reason', 'use')->orderBy('id')->get();
        $this->assertCount(2, $uses);
        $this->assertSame('-1', $uses[0]->quantity->formatted());
        $this->assertSame('-3.00', $uses[0]->value->toDecimal());
        $this->assertStringContainsString($order->order_no, $uses[0]->note);
        $this->assertSame('SOON', $uses[1]->batch_no, 'the batch that expires first is used first');
    }

    public function test_a_batch_running_out_is_finished_before_the_next_is_opened(): void
    {
        $this->stockUp();

        $this->place([$this->cbc]); // 2.5 from SOON, 1.5 left there
        $this->place([$this->cbc]); // 1.5 from SOON, 1 from LATE

        $second = Movement::where('reason', 'use')->where('item_id', $this->reagent->id)->orderBy('id')->get()->slice(1)->values();
        $this->assertSame([['SOON', '-1.5'], ['LATE', '-1']], $second->map(fn (Movement $m) => [$m->batch_no, $m->quantity->formatted()])->all());
        $this->assertSame('99', app(StockLedger::class)->batchQuantity($this->reagent, 'LATE')->formatted());
    }

    public function test_billing_never_waits_for_stock_and_the_gap_is_recorded(): void
    {
        $this->buy([['item_id' => $this->tubes->id, 'quantity' => '1', 'unit_cost' => '3.00']]); // no reagent at all

        $first = $this->place([$this->cbc]);
        $second = $this->place([$this->cbc]);

        $this->assertNotNull($first->invoice);
        $this->assertNotNull($second->invoice);
        $this->assertSame('0 @ 0.00', $this->stock($this->tubes));

        $gaps = Shortfall::orderBy('id')->get()->map(fn (Shortfall $s) => [$s->item->code, $s->quantity->formatted()])->all();
        $this->assertSame([['CBCREAG', '2.5'], ['EDTA', '1'], ['CBCREAG', '2.5']], $gaps);
    }

    public function test_the_same_event_twice_takes_nothing_more(): void
    {
        $this->stockUp();
        $order = $this->place([$this->cbc]);

        event(new OrderPlaced($order));
        app(StockUse::class)->forOrder($order);

        $this->assertSame('9 @ 27.00', $this->stock($this->tubes));
        $this->assertSame(2, Movement::where('reason', 'use')->count());
    }

    public function test_a_cancelled_test_puts_back_exactly_what_it_took(): void
    {
        $this->stockUp();
        $glucose = $this->service('Glucose', '150');
        $order = $this->place([$this->cbc, $glucose]);
        $item = OrderItem::where('order_id', $order->id)->where('service_id', $this->cbc->id)->sole();

        app(AdjustOrder::class)->cancelItem($item, 'Patient left');

        $this->assertSame('10 @ 30.00', $this->stock($this->tubes));
        $this->assertSame('104 @ 208.00', $this->stock($this->reagent));
        $this->assertSame('4', app(StockLedger::class)->batchQuantity($this->reagent, 'SOON')->formatted(), 'back into the same batch');

        event(new OrderItemCancelled($item->fresh()));
        $this->assertSame(2, Movement::where('reason', 'unuse')->count(), 'putting back twice changes nothing');
    }

    public function test_a_cancelled_order_puts_back_its_tests_and_closes_its_gaps(): void
    {
        $this->buy([['item_id' => $this->tubes->id, 'quantity' => '5', 'unit_cost' => '3.00']]);
        $order = $this->place([$this->cbc]);
        $this->assertSame(1, Shortfall::whereNull('resolved_at')->count());

        app(AdjustOrder::class)->cancelOrder($order, 'Wrong patient');

        $this->assertSame('5 @ 15.00', $this->stock($this->tubes));
        $this->assertSame(0, Shortfall::whereNull('resolved_at')->count());
        $this->assertSame('Test cancelled', Shortfall::sole()->resolve_note);
    }

    public function test_a_test_added_later_and_tests_inside_a_package_use_stock_too(): void
    {
        $this->stockUp();
        $package = $this->package('Health check', '1000', $this->cbc);

        $order = $this->place([$package]);
        $this->assertSame('9 @ 27.00', $this->stock($this->tubes), 'the CBC inside the package');

        $esr = $this->service('ESR', '150');
        Usage::create(['service_id' => $esr->id, 'item_id' => $this->tubes->id, 'quantity' => '1']);
        app(AdjustOrder::class)->addItem($order, $esr->id);
        $this->assertSame('8 @ 24.00', $this->stock($this->tubes));
    }

    public function test_a_supply_billed_on_its_own_can_use_its_stock_item(): void
    {
        $needle = $this->item('NEEDLE', 'Needle 23G');
        $this->buy([['item_id' => $needle->id, 'quantity' => '50', 'unit_cost' => '1.00']]);
        $needleService = $this->service('Needle', '5', ServiceType::Other);
        Usage::create(['service_id' => $needleService->id, 'item_id' => $needle->id, 'quantity' => '1']);
        $this->cbc->addons()->sync([$needleService->id]);
        $this->stockUp();

        $this->place([$this->cbc]);

        $this->assertSame('49 @ 49.00', $this->stock($needle));
    }

    public function test_nothing_is_taken_without_a_rule_for_an_inactive_item_or_with_the_module_off(): void
    {
        $this->stockUp();
        $this->place([$this->service('X-ray', '500', ServiceType::Imaging)]);
        $this->assertSame(0, Movement::where('reason', 'use')->count());

        $this->tubes->update(['is_active' => false]);
        $this->place([$this->cbc]);
        $this->assertSame('10 @ 30.00', $this->stock($this->tubes));

        app(ModuleManager::class)->disable('inventory');
        $this->place([$this->cbc]);
        $this->assertSame(1, Movement::where('reason', 'use')->count(), 'only the reagent of the second order');
    }

    public function test_a_stock_failure_never_stops_the_bill(): void
    {
        $this->app->bind(StockUse::class, fn () => throw new RuntimeException('stock exploded'));

        $order = $this->place([$this->cbc]);

        $this->assertTrue($order->exists);
        $this->assertNotNull($order->invoice);
    }
}
