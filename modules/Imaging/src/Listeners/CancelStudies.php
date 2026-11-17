<?php

declare(strict_types=1);

namespace Modules\Imaging\Listeners;

use App\Core\Modules\ModuleListener;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Imaging\Models\Study;

/**
 * Cancels imaging studies not yet started when the front desk cancels a
 * test or an order. Safe to run twice.
 */
final class CancelStudies extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'imaging';
    }

    protected function process(object $event): void
    {
        $orderId = $event instanceof OrderCancelled ? $event->order->id : $event->item->order_id;

        DB::connection('clinic')->transaction(function () use ($orderId): void {
            $cancelled = OrderItem::where('order_id', $orderId)->where('status', ItemStatus::Cancelled->value)->select('id');

            foreach (Study::where('order_id', $orderId)->whereIn('order_item_id', $cancelled)->where('status', 'pending')->lockForUpdate()->get() as $study) {
                $study->forceFill(['status' => 'cancelled', 'lock_version' => $study->lock_version + 1])->save();
            }
        });
    }
}
