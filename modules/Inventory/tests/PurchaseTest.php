<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Export\DataExporter;
use App\Core\Modules\ModuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Expense;
use Modules\Finance\Support\Ledger;
use Modules\Inventory\Actions\ManagePurchases;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\PurchaseLine;
use Modules\Inventory\Support\InventoryException;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\StockLedger;

final class PurchaseTest extends InventoryTestCase
{
    public function test_a_purchase_brings_stock_in_at_its_cost(): void
    {
        $tubes = $this->item();
        $needles = $this->item('NEEDLE', 'Needle 23G');

        $purchase = $this->buy([
            ['item_id' => $tubes->id, 'quantity' => '100', 'unit_cost' => '3.50'],
            ['item_id' => $needles->id, 'quantity' => '200', 'unit_cost' => '1.25'],
        ]);

        $this->assertMatchesRegularExpression('/^PO-\d{4}-00001$/', $purchase->purchase_no);
        $this->assertSame('600.00', $purchase->total->toDecimal());
        $this->assertSame('unpaid', $purchase->state());
        $this->assertSame('100 @ 350.00', $this->stock($tubes));
        $this->assertSame('200 @ 250.00', $this->stock($needles));
        $this->assertSame(2, Movement::where('reason', 'purchase')->count());

        $audit = AuditLog::where('action', 'purchase.recorded')->sole();
        $this->assertSame('600.00', $audit->after['total']);
    }

    public function test_the_average_cost_follows_the_purchases(): void
    {
        $tubes = $this->item();

        $this->buy([['item_id' => $tubes->id, 'quantity' => '100', 'unit_cost' => '3.00']]);
        $this->buy([['item_id' => $tubes->id, 'quantity' => '50', 'unit_cost' => '4.50']]);

        $tubes->refresh();
        $this->assertSame('150 @ 525.00', $this->stock($tubes));
        $this->assertSame('3.50', $tubes->averageCost()->toDecimal());
    }

    public function test_fractions_of_a_unit_are_exact(): void
    {
        $reagent = $this->item('CBCREAG', 'CBC diluent', true, 'ml');

        $this->buy([['item_id' => $reagent->id, 'quantity' => '2.5', 'unit_cost' => '3.33', 'expires_on' => now('Asia/Dhaka')->addYear()->toDateString()]]);

        $this->assertSame('2.5 @ 8.33', $this->stock($reagent));
    }

    public function test_an_item_with_an_expiry_date_needs_one_and_it_must_not_have_passed(): void
    {
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');

        foreach ([[], ['expires_on' => now('Asia/Dhaka')->subDay()->toDateString()]] as $extra) {
            try {
                $this->buy([['item_id' => $reagent->id, 'quantity' => '500', 'unit_cost' => '2'] + $extra]);
                $this->fail('A purchase without a usable expiry date was accepted.');
            } catch (InventoryException $e) {
                $this->assertStringContainsString('Glucose reagent', $e->getMessage());
            }
        }

        $this->buy([['item_id' => $reagent->id, 'quantity' => '500', 'unit_cost' => '2', 'expires_on' => '2027-06-30']]);
        $line = PurchaseLine::sole();
        $this->assertSame('EXP-20270630', $line->batch_no, 'without a batch number, stock is kept under its expiry date');
        $this->assertSame('500', app(StockLedger::class)->batchQuantity($reagent, 'EXP-20270630')->formatted());
    }

    public function test_bad_purchases_are_refused_and_change_nothing(): void
    {
        $tubes = $this->item();
        $inactive = $this->item('OLD', 'Old tube');
        $inactive->update(['is_active' => false]);
        $closed = $this->supplier('Closed Ltd');
        $closed->update(['is_active' => false]);

        $cases = [
            'no lines' => [[], null, null],
            'zero quantity' => [[['item_id' => $tubes->id, 'quantity' => '0', 'unit_cost' => '3']], null, null],
            'not a number' => [[['item_id' => $tubes->id, 'quantity' => 'ten', 'unit_cost' => '3']], null, null],
            'negative cost' => [[['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '-3']], null, null],
            'inactive item' => [[['item_id' => $inactive->id, 'quantity' => '10', 'unit_cost' => '3']], null, null],
            'unknown item' => [[['item_id' => 999, 'quantity' => '10', 'unit_cost' => '3']], null, null],
            'inactive supplier' => [[['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3']], $closed, null],
            'future date' => [[['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3']], null, now('Asia/Dhaka')->addDay()->toDateString()],
        ];

        $this->supplier();

        foreach ($cases as $name => [$lines, $supplier, $date]) {
            try {
                $this->buy($lines, $supplier, $date);
                $this->fail("Refused case [{$name}] was accepted.");
            } catch (InventoryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, DB::connection('clinic')->table('inv_purchases')->count());
        $this->assertSame('0 @ 0.00', $this->stock($tubes));
    }

    public function test_voiding_takes_the_stock_out_again(): void
    {
        $tubes = $this->item();
        $first = $this->buy([['item_id' => $tubes->id, 'quantity' => '100', 'unit_cost' => '3.00']]);
        $this->buy([['item_id' => $tubes->id, 'quantity' => '50', 'unit_cost' => '4.50']]);

        app(ManagePurchases::class)->void($first, 'Entered twice');

        $this->assertSame('50 @ 225.00', $this->stock($tubes));
        $this->assertSame('void', $first->fresh()->state());
        $this->assertSame(1, Movement::where('reason', 'purchase_void')->count());
        $this->assertSame('Entered twice', AuditLog::where('action', 'purchase.voided')->sole()->context['reason']);

        $this->expectException(InventoryException::class);
        app(ManagePurchases::class)->void($first->fresh(), 'again');
    }

    public function test_a_purchase_whose_stock_was_used_cannot_be_voided(): void
    {
        $tubes = $this->item();
        $purchase = $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00']]);

        // Some of it was taken out (the next step adds the screens for this).
        DB::connection('clinic')->transaction(fn () => app(StockLedger::class)->post($tubes, Quantity::fromDecimal('-4'), null, 'issue'));
        $this->assertSame('6 @ 18.00', $this->stock($tubes));

        try {
            app(ManagePurchases::class)->void($purchase, 'Wrong');
            $this->fail('A purchase was voided although its stock is gone.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('Only 6 piece of EDTA tube is in stock', $e->getMessage());
        }

        $this->assertSame('6 @ 18.00', $this->stock($tubes));
        $this->assertSame('unpaid', $purchase->fresh()->state());
    }

    public function test_stock_can_never_go_below_zero_and_the_last_unit_takes_the_last_poisha(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '3', 'unit_cost' => '33.33']]);
        $ledger = app(StockLedger::class);

        DB::connection('clinic')->transaction(fn () => $ledger->post($tubes, Quantity::fromDecimal('-1'), null, 'issue'));
        $this->assertSame('2 @ 66.66', $this->stock($tubes));
        DB::connection('clinic')->transaction(fn () => $ledger->post($tubes, Quantity::fromDecimal('-2'), null, 'issue'));
        $this->assertSame('0 @ 0.00', $this->stock($tubes));

        $this->expectException(InventoryException::class);
        DB::connection('clinic')->transaction(fn () => $ledger->post($tubes, Quantity::fromDecimal('-1'), null, 'issue'));
    }

    public function test_paying_without_finance_keeps_the_date_only(): void
    {
        $purchase = $this->buy([['item_id' => $this->item()->id, 'quantity' => '10', 'unit_cost' => '5']]);

        app(ManagePurchases::class)->pay($purchase, now('Asia/Dhaka')->toDateString(), null, 'Cash at the door');

        $purchase->refresh();
        $this->assertSame('paid', $purchase->state());
        $this->assertNull($purchase->expense_id);
        $this->assertSame('Cash at the door', $purchase->payment_note);

        try {
            app(ManagePurchases::class)->pay($purchase, now('Asia/Dhaka')->toDateString());
            $this->fail('A purchase was paid twice.');
        } catch (InventoryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InventoryException::class);
        app(ManagePurchases::class)->void($purchase, 'too late');
    }

    public function test_paying_with_finance_records_an_expense_once(): void
    {
        app(ModuleManager::class)->enable('finance');
        $cash = Account::create(['name' => 'Cash box', 'type' => 'cash', 'is_default' => true]);
        $purchase = $this->buy([['item_id' => $this->item()->id, 'quantity' => '100', 'unit_cost' => '3.50']]);

        try {
            app(ManagePurchases::class)->pay($purchase, now('Asia/Dhaka')->toDateString());
            $this->fail('Paid without saying from which account.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('account', $e->getMessage());
        }

        app(ManagePurchases::class)->pay($purchase, now('Asia/Dhaka')->toDateString(), $cash->id);

        $expense = Expense::sole();
        $this->assertSame($expense->id, $purchase->fresh()->expense_id);
        $this->assertSame('350.00', $expense->amount->toDecimal());
        $this->assertSame('Purchases', $expense->category->name);
        $this->assertSame('Popular Diagnostics Supply', $expense->payee);
        $this->assertSame('-350.00', app(Ledger::class)->balance($cash->fresh())->toDecimal());
    }

    public function test_every_action_checks_its_permission(): void
    {
        $tubes = $this->item();
        $purchase = $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '5']]);
        $this->actingAs($this->staff('lab_technician')); // may see stock only

        foreach ([
            fn () => $this->buy([['item_id' => $tubes->id, 'quantity' => '1', 'unit_cost' => '5']]),
            fn () => app(ManagePurchases::class)->void($purchase, 'x'),
            fn () => app(ManagePurchases::class)->pay($purchase, now('Asia/Dhaka')->toDateString()),
        ] as $try) {
            try {
                $try();
                $this->fail('A lab technician changed stock.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($this->staff('accountant')); // records and pays, but does not void
        $this->buy([['item_id' => $tubes->id, 'quantity' => '1', 'unit_cost' => '5']]);
        $this->expectException(AuthorizationException::class);
        app(ManagePurchases::class)->void($purchase, 'x');
    }

    public function test_lines_and_movements_are_never_edited(): void
    {
        $this->buy([['item_id' => $this->item()->id, 'quantity' => '10', 'unit_cost' => '5']]);

        foreach ([fn () => PurchaseLine::first()->update(['quantity' => '1']), fn () => Movement::first()->delete(), fn () => Movement::first()->update(['quantity' => '1'])] as $try) {
            try {
                $try();
                $this->fail('An append-only row was changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_nightly_check_puts_wrong_balances_right(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '5']]);
        DB::connection('clinic')->table('inv_items')->where('id', $tubes->id)->update(['quantity' => '99.000', 'stock_value' => '1.00']);

        $this->artisan('inventory:check')->expectsOutputToContain('1 item(s) corrected')->assertSuccessful();

        $this->assertSame('10 @ 50.00', $this->stock($tubes));
        $this->artisan('inventory:check')->expectsOutputToContain('0 item(s) corrected')->assertSuccessful();
    }

    public function test_the_tables_are_in_the_data_export(): void
    {
        $datasets = app(DataExporter::class)->datasets();

        foreach (['inv_items', 'inv_suppliers', 'inv_purchases', 'inv_purchase_lines', 'inv_movements'] as $table) {
            $this->assertArrayHasKey($table, $datasets);
        }
    }
}
