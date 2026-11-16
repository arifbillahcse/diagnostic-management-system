<?php

declare(strict_types=1);

namespace Modules\Finance\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\Posting;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\PaymentReceived;
use Modules\FrontDesk\Events\RefundIssued;
use Modules\FrontDesk\Models\Invoice;

/**
 * Keeps the accounts and referral commission in step with the front desk.
 * Every step is safe to repeat.
 */
final class RecordFrontDeskMoney extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'finance';
    }

    protected function process(object $event): void
    {
        $invoice = match (true) {
            $event instanceof PaymentReceived => tap($event->payment->invoice, fn () => app(Posting::class)->payment($event->payment)),
            $event instanceof RefundIssued => tap($event->refund->invoice, fn () => app(Posting::class)->refund($event->refund)),
            $event instanceof OrderItemCancelled, $event instanceof OrderItemAdded => Invoice::where('order_id', $event->item->order_id)->first(),
            $event instanceof OrderCancelled => Invoice::where('order_id', $event->order->id)->first(),
            default => null,
        };

        if ($invoice !== null) {
            app(Commission::class)->settle($invoice);
        }
    }
}
