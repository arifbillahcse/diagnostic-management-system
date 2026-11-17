<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Inventory\Actions\AdjustStock;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Shortfall;
use Modules\Inventory\Support\InventoryException;
use Modules\Inventory\Support\StockLedger;

final class AdjustTest extends InventoryTestCase
{
    private function adjust(): AdjustStock
    {
        return app(AdjustStock::class);
    }

    public function test_stock_is_taken_out_by_hand_with_a_reason(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00']]);

        $this->adjust()->takeOut($tubes, 'issue', '2');
        $this->adjust()->takeOut($tubes, 'waste', '1', null, 'Dropped on the floor');

        $this->assertSame('7 @ 21.00', $this->stock($tubes));
        $this->assertSame(['issue', 'waste'], Movement::whereIn('reason', ['issue', 'waste'])->orderBy('id')->pluck('reason')->all());
        $this->assertSame('Dropped on the floor', AuditLog::where('action', 'stock.taken_out')->latest('id')->first()->context['note']);
    }

    public function test_taking_out_is_refused_when_it_makes_no_sense(): void
    {
        $tubes = $this->item();
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');
        $this->buy([
            ['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00'],
            ['item_id' => $reagent->id, 'quantity' => '50', 'unit_cost' => '2', 'batch_no' => 'A1', 'expires_on' => '2027-06-30'],
        ]);

        foreach ([
            'waste without a note' => fn () => $this->adjust()->takeOut($tubes, 'waste', '1'),
            'more than in stock' => fn () => $this->adjust()->takeOut($tubes, 'issue', '11'),
            'zero' => fn () => $this->adjust()->takeOut($tubes, 'issue', '0'),
            'not a number' => fn () => $this->adjust()->takeOut($tubes, 'issue', 'two'),
            'unknown reason' => fn () => $this->adjust()->takeOut($tubes, 'use', '1'),
            'batch item without a batch' => fn () => $this->adjust()->takeOut($reagent, 'expired', '5', null, 'old'),
            'more than in the batch' => fn () => $this->adjust()->takeOut($reagent, 'expired', '51', 'A1', 'old'),
        ] as $name => $try) {
            try {
                $try();
                $this->fail("Refused case [{$name}] was accepted.");
            } catch (InventoryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->adjust()->takeOut($reagent, 'expired', '50', 'A1', 'Expired last week');
        $this->assertSame('0 @ 0.00', $this->stock($reagent));
        $this->assertSame('10 @ 30.00', $this->stock($tubes));
    }

    public function test_a_count_records_only_the_difference_and_needs_a_reason(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00']]);

        $this->assertNull($this->adjust()->count($tubes, '10'), 'a matching count changes nothing');
        $this->assertSame(0, Movement::where('reason', 'count')->count());

        try {
            $this->adjust()->count($tubes, '8');
            $this->fail('A count with a difference was accepted without a reason.');
        } catch (InventoryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('-2', $this->adjust()->count($tubes, '8', null, 'Two broken, not recorded')->formatted());
        $this->assertSame('8 @ 24.00', $this->stock($tubes));

        // Found stock is valued at today's average cost.
        $this->assertSame('3', $this->adjust()->count($tubes, '11', null, 'A box found in the store')->formatted());
        $this->assertSame('11 @ 33.00', $this->stock($tubes));

        $audit = AuditLog::where('action', 'stock.counted')->latest('id')->first();
        $this->assertSame(['quantity' => '8'], $audit->before);
        $this->assertSame(['quantity' => '11'], $audit->after);
    }

    public function test_a_batch_is_counted_on_its_own(): void
    {
        $reagent = $this->item('GLU', 'Glucose reagent', true, 'ml');
        $this->buy([
            ['item_id' => $reagent->id, 'quantity' => '50', 'unit_cost' => '2', 'batch_no' => 'A1', 'expires_on' => '2027-06-30'],
            ['item_id' => $reagent->id, 'quantity' => '30', 'unit_cost' => '2', 'batch_no' => 'B2', 'expires_on' => '2027-09-30'],
        ]);

        $this->adjust()->count($reagent, '45', 'A1', 'Spilled');

        $ledger = app(StockLedger::class);
        $this->assertSame('45', $ledger->batchQuantity($reagent, 'A1')->formatted());
        $this->assertSame('30', $ledger->batchQuantity($reagent, 'B2')->formatted());
        $this->assertSame('75 @ 150.00', $this->stock($reagent));
    }

    public function test_a_shortfall_is_sorted_out_once_with_a_note(): void
    {
        $tubes = $this->item();
        $order = $this->order(['400']);
        $shortfall = Shortfall::create(['order_item_id' => $order->items()->first()->id, 'item_id' => $tubes->id, 'quantity' => '1']);

        try {
            $this->adjust()->resolve($shortfall, '');
            $this->fail('Sorted out without a note.');
        } catch (InventoryException) {
            $this->addToAssertionCount(1);
        }

        $this->adjust()->resolve($shortfall, 'Purchase entered late');
        $this->assertNotNull($shortfall->fresh()->resolved_at);

        $this->expectException(InventoryException::class);
        $this->adjust()->resolve($shortfall->fresh(), 'again');
    }

    public function test_only_those_who_may_adjust_stock_can(): void
    {
        $tubes = $this->item();
        $this->buy([['item_id' => $tubes->id, 'quantity' => '10', 'unit_cost' => '3.00']]);

        foreach (['lab_technician', 'accountant'] as $role) {
            $this->actingAs($this->staff($role));

            foreach ([fn () => $this->adjust()->takeOut($tubes, 'issue', '1'), fn () => $this->adjust()->count($tubes, '9', null, 'x')] as $try) {
                try {
                    $try();
                    $this->fail("A {$role} adjusted stock.");
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame('10 @ 30.00', $this->stock($tubes));
    }
}
