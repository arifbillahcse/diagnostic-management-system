<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\LineKind;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderItemCancelling;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\InvoiceLine;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\DailyFigures;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\FrontDesk\Support\InvoiceCalculator;

/**
 * Changes an order after its invoice has been issued.
 *
 * Issued invoice lines are never edited. Adding or cancelling a test adds
 * an adjustment line, so the invoice shows its own history.
 */
final class AdjustOrder
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly DailyFigures $daily,
    ) {}

    public function addItem(Order $order, int $serviceId): OrderItem
    {
        Gate::authorize(Permissions::ORDERS_ADJUST, $order->branch_id);

        $item = DB::connection('clinic')->transaction(function () use ($order, $serviceId): OrderItem {
            [$order, $invoice] = $this->lock($order);

            $service = Service::with('packageItems')->where('is_active', true)->find($serviceId)
                ?? throw new FrontDeskException('The chosen service is not available.');

            $incoming = [$service->id, ...($service->isPackage() ? $service->packageItems->pluck('id')->all() : [])];

            if ($service->isPackage() && count($incoming) === 1) {
                throw new FrontDeskException("The package \"{$service->name}\" has no tests in it.");
            }

            if ($order->items()->where('status', ItemStatus::Active->value)->whereIn('service_id', $incoming)->exists()) {
                throw new FrontDeskException("\"{$service->name}\" overlaps with a test already on this order.");
            }

            // Added at full price. A discount applies only to what was on
            // the order when the discount was approved.
            $item = $this->createItem($order, $service, $service->price, null);

            foreach ($service->isPackage() ? $service->packageItems : [] as $component) {
                $this->createItem($order, $component, Money::zero(), $item->id);
            }

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'order_item_id' => $item->id,
                'kind' => LineKind::Adjustment,
                'description' => 'Added: '.$service->name,
                'amount' => $service->price,
                'created_by' => Auth::id(),
            ]);

            $this->calculator->refresh($invoice);

            $this->audit->record('order.item_added', $order, [], [
                'service' => $service->name,
                'amount' => $service->price->toDecimal(),
                'invoice_total' => $invoice->total->toDecimal(),
            ]);

            return $item;
        });

        event(new OrderItemAdded($item));

        return $item;
    }

    public function cancelItem(OrderItem $item, string $reason): OrderItem
    {
        $order = $item->order;
        Gate::authorize(Permissions::ORDERS_ADJUST, $order->branch_id);
        $this->requireReason($reason);

        [$item, $orderCancelled] = DB::connection('clinic')->transaction(function () use ($item, $order, $reason): array {
            [$order, $invoice] = $this->lock($order);
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! $item->isActive()) {
                throw new FrontDeskException('This test is already cancelled.');
            }

            if ($item->isPackageComponent()) {
                throw new FrontDeskException('A test inside a package cannot be cancelled on its own. Cancel the package.');
            }

            // Other modules may refuse, for example the laboratory once a
            // result has been entered.
            event(new OrderItemCancelling($item));

            $cancellation = [
                'status' => ItemStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => trim($reason),
            ];

            $item->update($cancellation);
            OrderItem::where('package_item_of', $item->id)->where('status', ItemStatus::Active->value)->update($cancellation);

            // Take back exactly what this item contributed: its price less
            // its share of the discount.
            if ($item->net_amount->isPositive()) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'order_item_id' => $item->id,
                    'kind' => LineKind::Adjustment,
                    'description' => 'Cancelled: '.$item->service_name,
                    'amount' => $item->net_amount->negate(),
                    'created_by' => Auth::id(),
                ]);
            }

            $this->calculator->refresh($invoice);

            $nothingLeft = ! $order->items()->where('status', ItemStatus::Active->value)->exists();

            if ($nothingLeft) {
                $this->transition($order, OrderStatus::Cancelled, $reason);
            }

            $this->audit->record('order.item_cancelled', $order, [], [
                'service' => $item->service_name,
                'amount' => $item->net_amount->negate()->toDecimal(),
                'invoice_total' => $invoice->total->toDecimal(),
                'due_after' => $invoice->due_amount->toDecimal(),
            ], ['reason' => trim($reason)]);

            return [$item, $nothingLeft];
        });

        // The order's own day may be a finished one whose stored figures
        // (order and patient counts) have just changed.
        $this->daily->forget($order->ordered_at, $order->branch_id);

        event(new OrderItemCancelled($item));

        if ($orderCancelled) {
            event(new OrderCancelled($order->refresh()));
        }

        return $item;
    }

    /**
     * Cancel a whole order and void its invoice. Only possible while the
     * clinic holds no money for it; otherwise cancel the tests and refund.
     */
    public function cancelOrder(Order $order, string $reason): Order
    {
        Gate::authorize(Permissions::ORDERS_CANCEL, $order->branch_id);
        $this->requireReason($reason);

        $order = DB::connection('clinic')->transaction(function () use ($order, $reason): Order {
            [$order, $invoice] = $this->lock($order);

            if (! $invoice->netPaid()->isZero()) {
                throw new FrontDeskException('Money has been received for this order. Cancel the tests and refund the patient instead.');
            }

            $now = now();

            foreach ($order->items()->where('status', ItemStatus::Active->value)->get() as $active) {
                event(new OrderItemCancelling($active));
            }

            $order->items()->where('status', ItemStatus::Active->value)->update([
                'status' => ItemStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by' => Auth::id(),
                'cancel_reason' => trim($reason),
            ]);

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => $now,
                'voided_by' => Auth::id(),
                'void_reason' => trim($reason),
            ])->save();

            $this->calculator->refresh($invoice);
            $this->transition($order, OrderStatus::Cancelled, $reason);

            $this->audit->record('order.cancelled', $order, ['status' => OrderStatus::Confirmed->value], [
                'status' => OrderStatus::Cancelled->value,
                'invoice_no' => $invoice->invoice_no,
                'invoice_status' => $invoice->status->value,
            ], ['reason' => trim($reason)]);

            return $order;
        });

        // A void invoice no longer counts as billed, on any day it had a line.
        $this->daily->forget($order->ordered_at, $order->branch_id);

        foreach (InvoiceLine::whereIn('invoice_id', Invoice::where('order_id', $order->id)->select('id'))->pluck('created_at') as $lineWrittenAt) {
            $this->daily->forget($lineWrittenAt, $order->branch_id);
        }

        event(new OrderCancelled($order));

        return $order;
    }

    /**
     * Lock the order's invoice (the row every money operation locks), then
     * re-read both, and refuse if the order can no longer change.
     *
     * @return array{0: Order, 1: Invoice}
     */
    private function lock(Order $order): array
    {
        $invoice = Invoice::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($order->status === OrderStatus::Cancelled || $invoice->isVoid()) {
            throw new FrontDeskException('This order is cancelled.');
        }

        if ($order->status === OrderStatus::Completed) {
            throw new FrontDeskException('This order is completed and can no longer be changed.');
        }

        return [$order, $invoice];
    }

    private function transition(Order $order, OrderStatus $next, string $reason): void
    {
        if (! $order->status->canBecome($next)) {
            throw new FrontDeskException("An order that is {$order->status->label()} cannot become {$next->label()}.");
        }

        $order->forceFill([
            'status' => $next,
            'cancelled_at' => now(),
            'cancelled_by' => Auth::id(),
            'cancel_reason' => trim($reason),
        ])->save();
    }

    private function createItem(Order $order, Service $service, Money $price, ?int $packageItemOf): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'service_type' => $service->type,
            'unit_price' => $price,
            'discount_amount' => Money::zero(),
            'net_amount' => $price,
            'package_item_of' => $packageItemOf,
            'status' => ItemStatus::Active,
        ]);
    }

    private function requireReason(string $reason): void
    {
        if (blank($reason)) {
            throw new FrontDeskException('A reason is required.');
        }
    }
}
