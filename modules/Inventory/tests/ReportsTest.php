<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Inventory\Actions\AdjustStock;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\Usage;
use Modules\Inventory\Support\StockReports;

final class ReportsTest extends InventoryTestCase
{
    public function test_the_alerts_count_what_needs_attention(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Dhaka'));
        $low = $this->item(reorder: '20');
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');
        $this->buy([
            ['item_id' => $low->id, 'quantity' => '5', 'unit_cost' => '3.00'],
            ['item_id' => $reagent->id, 'quantity' => '10', 'unit_cost' => '2.00', 'batch_no' => 'SOON', 'expires_on' => '2026-12-01'],
            ['item_id' => $reagent->id, 'quantity' => '10', 'unit_cost' => '2.00', 'batch_no' => 'LATER', 'expires_on' => '2027-12-01'],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'Asia/Dhaka')); // SOON is 6 days away

        $alerts = app(StockReports::class)->alerts();

        $this->assertSame('55.00', $alerts['value']->toDecimal());
        $this->assertSame(1, $alerts['low']);
        $this->assertSame(1, $alerts['expiring']);
        $this->assertSame(0, $alerts['expired']);

        $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Asia/Dhaka'));
        $this->assertSame(1, app(StockReports::class)->alerts()['expired']);
        $this->assertSame([['SOON', -2], ['LATER', 363]], array_map(fn (array $b) => [$b['batch_no'], $b['days']], app(StockReports::class)->batches()));
    }

    public function test_the_use_report_shows_the_cost_of_stock_per_test_net_of_cancellations(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00']]);
        $cbc = $this->service('CBC', '400');
        Usage::create(['service_id' => $cbc->id, 'item_id' => $tubes->id, 'quantity' => '2']);

        $place = fn () => app(PlaceOrder::class)->handle($this->patient(), $this->branch, [$cbc->id]);
        $place();
        $place();
        $cancelled = $place();
        app(AdjustOrder::class)->cancelItem(OrderItem::where('order_id', $cancelled->id)->sole(), 'Mistake');
        app(AdjustStock::class)->takeOut($tubes, 'waste', '1', null, 'Broken');

        $today = now('Asia/Dhaka')->toDateString();
        $byTest = app(StockReports::class)->useByTest($today, $today);
        $this->assertSame('CBC', $byTest[0]['test']);
        $this->assertSame(3, $byTest[0]['times'], 'ordered three times');
        $this->assertSame('12.00', $byTest[0]['value']->toDecimal(), 'only the two that were not cancelled');

        $byItem = app(StockReports::class)->outByItem($today, $today);
        $this->assertSame('4', $byItem[0]['used']->formatted());
        $this->assertSame('1', $byItem[0]['other']->formatted());
        $this->assertSame('15.00', $byItem[0]['value']->toDecimal());
    }

    public function test_the_sample_data_command_loads_stock_and_use_rules(): void
    {
        $this->service('CBC', '400')->forceFill(['code' => 'CBC'])->save();
        $clinic = app(ClinicContext::class)->require();

        $this->artisan('inventory:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertSuccessful();

        $this->assertSame(7, Item::count());
        $this->assertSame(1, Purchase::count());
        $this->assertSame(3, Usage::count(), 'Only CBC exists');
        $this->assertSame('300', Item::where('code', 'EDTA')->value('quantity')->formatted());

        $this->artisan('inventory:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertFailed();
    }
}
