<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Inventory\Support\StockUse;
use Throwable;

/**
 * Keeps stock in step with the front desk. A problem with stock must never
 * stop a bill, so any failure is only reported; the shortfall list and the
 * nightly check catch what is missed.
 */
final class UseStockForOrders extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'inventory';
    }

    protected function process(object $event): void
    {
        try {
            $use = app(StockUse::class);

            match (true) {
                $event instanceof OrderPlaced => $use->forOrder($event->order),
                $event instanceof OrderItemAdded => $use->forItem($event->item),
                $event instanceof OrderItemCancelled => $use->putBack($event->item),
                $event instanceof OrderCancelled => $use->putBackOrder($event->order),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);
        }
    }
}
