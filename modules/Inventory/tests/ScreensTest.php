<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Modules\ModuleManager;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Expense;
use Modules\Inventory\Filament\CreatePurchase;
use Modules\Inventory\Filament\ListMovements;
use Modules\Inventory\Filament\ManageItems;
use Modules\Inventory\Filament\ManageSuppliers;
use Modules\Inventory\Filament\ViewPurchase;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\Supplier;

final class ScreensTest extends InventoryTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_screens_follow_the_roles(): void
    {
        $purchase = $this->buy([['item_id' => $this->item()->id, 'quantity' => '10', 'unit_cost' => '5']]);
        $pages = ['/inventory/stock', '/inventory/purchases', '/inventory/purchases/create', "/inventory/purchases/{$purchase->public_id}", '/inventory/movements', '/inventory/suppliers'];

        foreach ($pages as $page) {
            $this->signIn($this->ownerUser)->get(self::BASE.$page)->assertOk();
        }

        $technician = $this->staff('lab_technician');
        $this->signIn($technician)->get(self::BASE.'/inventory/stock')->assertOk()->assertSee('EDTA tube');
        $this->signIn($technician)->get(self::BASE.'/inventory/purchases/create')->assertForbidden();

        $receptionist = $this->staff('receptionist');
        $this->signIn($receptionist)->get(self::BASE.'/inventory/stock')->assertForbidden();

        $this->actingAs($this->ownerUser);
        app(ModuleManager::class)->disable('inventory');
        $this->signIn($this->ownerUser)->get(self::BASE.'/inventory/stock')->assertForbidden();
    }

    public function test_items_and_suppliers_are_set_up_from_their_screens(): void
    {
        Livewire::test(ManageItems::class)
            ->callAction('create', ['code' => 'edta', 'name' => 'EDTA tube', 'category' => 'consumable', 'unit' => 'piece', 'reorder_level' => '50', 'tracks_expiry' => false, 'is_active' => true])
            ->assertHasNoActionErrors();

        $item = Item::sole();
        $this->assertSame('EDTA', $item->code);
        $this->assertSame('50', $item->reorder_level->formatted());
        $this->assertSame('out', $item->level());

        Livewire::test(ManageItems::class)
            ->callAction('create', ['code' => 'X', 'name' => 'Bad', 'category' => 'consumable', 'unit' => 'piece', 'reorder_level' => 'many'])
            ->assertHasActionErrors(['reorder_level']);

        Livewire::test(ManageSuppliers::class)
            ->callAction('create', ['name' => 'Popular Diagnostics Supply', 'phone' => '01700000000', 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('01700000000', Supplier::sole()->phone);
    }

    public function test_a_purchase_is_recorded_from_the_screen_with_its_lines(): void
    {
        $tubes = $this->item();
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');
        $supplier = $this->supplier();

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'purchase_date' => now('Asia/Dhaka')->toDateString(),
                'supplier_bill_no' => 'B-7781',
                'lines' => [
                    ['item_id' => $tubes->id, 'quantity' => '100', 'unit_cost' => '3.50'],
                    ['item_id' => $reagent->id, 'quantity' => '500', 'unit_cost' => '2', 'batch_no' => 'L42', 'expires_on' => '2027-06-30'],
                ],
            ])
            ->assertSee('Total: ৳ 1,350.00')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Purchase recorded');

        $purchase = Purchase::sole();
        $this->assertSame('B-7781', $purchase->supplier_bill_no);
        $this->assertSame('1350.00', $purchase->total->toDecimal());
        $this->assertSame('500 @ 1000.00', $this->stock($reagent));
    }

    public function test_a_refused_purchase_shows_why_and_records_nothing(): void
    {
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $this->supplier()->id,
                'purchase_date' => now('Asia/Dhaka')->toDateString(),
                'lines' => [['item_id' => $reagent->id, 'quantity' => '500', 'unit_cost' => '2', 'expires_on' => now('Asia/Dhaka')->subDay()->toDateString()]],
            ])
            ->call('create')
            ->assertNotified('Not done');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_purchase_is_paid_and_voided_from_its_page(): void
    {
        $tubes = $this->item();
        $paid = $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '5']]);
        $wrong = $this->buy([['item_id' => $tubes->id, 'quantity' => '4', 'unit_cost' => '5']]);

        Livewire::test(ViewPurchase::class, ['record' => $paid->public_id])
            ->assertSee('Unpaid')
            ->callAction('pay', ['date' => now('Asia/Dhaka')->toDateString(), 'note' => 'Cash'])
            ->assertNotified('Purchase marked paid');
        $this->assertSame('paid', $paid->fresh()->state());

        Livewire::test(ViewPurchase::class, ['record' => $paid->public_id])->assertActionHidden('pay')->assertActionHidden('void');

        Livewire::test(ViewPurchase::class, ['record' => $wrong->public_id])
            ->callAction('void', ['reason' => 'Entered twice'])
            ->assertNotified('Purchase voided');
        $this->assertSame('10 @ 50.00', $this->stock($tubes));

        $this->actingAs($this->staff('lab_technician'));
        Livewire::test(ViewPurchase::class, ['record' => $paid->public_id])->assertActionHidden('pay');
    }

    public function test_with_finance_on_the_payment_asks_for_the_account(): void
    {
        app(ModuleManager::class)->enable('finance');
        $bank = Account::create(['name' => 'City Bank', 'type' => 'bank']);
        $purchase = $this->buy([['item_id' => $this->item()->id, 'quantity' => '10', 'unit_cost' => '5']]);

        Livewire::test(ViewPurchase::class, ['record' => $purchase->public_id])
            ->callAction('pay', ['date' => now('Asia/Dhaka')->toDateString(), 'account_id' => $bank->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Purchase marked paid');

        $this->assertSame($bank->id, Expense::sole()->account_id);
    }

    public function test_movements_can_be_filtered_by_item(): void
    {
        $tubes = $this->item();
        $needles = $this->item('NEEDLE', 'Needle 23G');
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '5'], ['item_id' => $needles->id, 'quantity' => '20', 'unit_cost' => '1']]);

        $tubeMove = Movement::where('item_id', $tubes->id)->sole();
        $needleMove = Movement::where('item_id', $needles->id)->sole();

        Livewire::test(ListMovements::class)
            ->assertCanSeeTableRecords([$tubeMove, $needleMove])
            ->filterTable('item_id', $tubes->id)
            ->assertCanSeeTableRecords([$tubeMove])
            ->assertCanNotSeeTableRecords([$needleMove]);
    }

    public function test_the_stock_list_shows_low_items(): void
    {
        $tubes = $this->item(reorder: '20');
        $this->buy([['item_id' => $tubes->id, 'quantity' => '15', 'unit_cost' => '5']]);
        $plenty = $this->item('NEEDLE', 'Needle 23G', reorder: '10');
        $this->buy([['item_id' => $plenty->id, 'quantity' => '100', 'unit_cost' => '1']]);

        Livewire::test(ManageItems::class)
            ->assertSee('Low')
            ->filterTable('low')
            ->assertCanSeeTableRecords([$tubes])
            ->assertCanNotSeeTableRecords([$plenty]);
    }
}
