<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Modules\ModuleManager;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Inventory\Actions\ManagePurchases;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\Supplier;

/**
 * Front desk set-up with the inventory module switched on.
 */
abstract class InventoryTestCase extends FrontDeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('inventory');
        $this->actingAs($this->ownerUser);
    }

    protected function item(string $code = 'EDTA', string $name = 'EDTA tube', bool $expiry = false, string $unit = 'piece', string $reorder = '0'): Item
    {
        return Item::create(['code' => $code, 'name' => $name, 'category' => $expiry ? 'reagent' : 'consumable', 'unit' => $unit, 'reorder_level' => $reorder, 'tracks_expiry' => $expiry]);
    }

    protected function supplier(string $name = 'Popular Diagnostics Supply'): Supplier
    {
        return Supplier::create(['name' => $name, 'phone' => '01700000000']);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function buy(array $lines, ?Supplier $supplier = null, ?string $date = null): Purchase
    {
        return app(ManagePurchases::class)->record(
            $this->branch,
            $supplier ?? Supplier::first() ?? $this->supplier(),
            $date ?? now('Asia/Dhaka')->toDateString(),
            $lines,
        );
    }

    protected function stock(Item $item): string
    {
        $item->refresh();

        return $item->quantity->formatted().' @ '.$item->stock_value->toDecimal();
    }
}
