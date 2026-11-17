<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Shortfall;
use Modules\Inventory\Models\Usage;

/**
 * Takes stock out when a test is ordered, and puts it back when the test is
 * cancelled, following the clinic's "stock use per test" rules.
 *
 * Billing never waits for stock: what is not on hand is recorded as a
 * shortfall instead. Every step has its own posting key, so running it twice
 * changes nothing.
 */
final class StockUse
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function forOrder(Order $order): void
    {
        foreach (OrderItem::where('order_id', $order->id)->where('status', ItemStatus::Active->value)->orderBy('id')->get() as $item) {
            $this->forItem($item, $order);
        }
    }

    public function forItem(OrderItem $orderItem, ?Order $order = null): void
    {
        $rules = Usage::where('service_id', $orderItem->service_id)->with('item')->get();

        if ($rules->isEmpty()) {
            return;
        }

        $order ??= Order::find($orderItem->order_id);

        DB::connection('clinic')->transaction(function () use ($rules, $orderItem, $order): void {
            foreach ($rules as $rule) {
                if (! $rule->item->is_active) {
                    continue;
                }

                $prefix = "use:{$orderItem->id}:{$rule->item_id}";

                // Already done for this test (a repeated event).
                if (Movement::where('posting_key', 'like', $prefix.':%')->exists() || Shortfall::where('order_item_id', $orderItem->id)->where('item_id', $rule->item_id)->exists()) {
                    continue;
                }

                $result = $this->ledger->takeOut(
                    $rule->item, $rule->quantity, 'use', $prefix, 'order_item', $orderItem->id, $order?->branch_id,
                    trim(($order?->order_no ?? '').' · '.$orderItem->service_name, ' ·'),
                );

                if ($result['short']->isPositive()) {
                    Shortfall::create(['order_item_id' => $orderItem->id, 'item_id' => $rule->item_id, 'quantity' => $result['short']]);
                }
            }
        });
    }

    /** A cancelled test: put back exactly what it took, into the same batches, and drop its open shortfalls. */
    public function putBack(OrderItem $orderItem): void
    {
        $ids = OrderItem::where('id', $orderItem->id)->orWhere('package_item_of', $orderItem->id)->pluck('id');

        DB::connection('clinic')->transaction(function () use ($ids): void {
            $uses = Movement::where('source_type', 'order_item')->whereIn('source_id', $ids)->where('reason', 'use')->with('item')->orderBy('id')->get();

            foreach ($uses as $use) {
                if (Movement::where('posting_key', "unuse:{$use->id}")->exists()) {
                    continue;
                }

                $this->ledger->post(
                    $use->item, $use->quantity->negate(), $use->value->negate(), 'unuse', 'order_item', $use->source_id, $use->branch_id,
                    $use->batch_no, $use->expires_on?->toDateString(), 'Test cancelled · '.$use->note, null, "unuse:{$use->id}",
                );
            }

            Shortfall::whereIn('order_item_id', $ids)->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolve_note' => 'Test cancelled']);
        });
    }

    public function putBackOrder(Order $order): void
    {
        foreach (OrderItem::where('order_id', $order->id)->whereNull('package_item_of')->get() as $item) {
            $this->putBack($item);
        }
    }
}
