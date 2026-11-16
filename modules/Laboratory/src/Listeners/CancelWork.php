<?php

declare(strict_types=1);

namespace Modules\Laboratory\Listeners;

use App\Core\Modules\ModuleListener;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Support\Progress;

/**
 * Cancels laboratory work when the front desk cancels a test or an order.
 * Safe to run twice: work already cancelled is left alone.
 */
final class CancelWork extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'laboratory';
    }

    protected function process(object $event): void
    {
        $orderId = $event instanceof OrderCancelled ? $event->order->id : $event->item->order_id;

        DB::connection('clinic')->transaction(function () use ($orderId): void {
            // Cancel work for every order item that is no longer active.
            $cancelledItems = OrderItem::where('order_id', $orderId)->where('status', ItemStatus::Cancelled->value)->select('id');

            $work = WorkItem::query()
                ->where('order_id', $orderId)
                ->whereIn('order_item_id', $cancelledItems)
                ->whereIn('status', [WorkStatus::AwaitingSample->value, WorkStatus::Ready->value])
                ->get();

            foreach ($work as $item) {
                $item->forceFill(['status' => WorkStatus::Cancelled, 'lock_version' => $item->lock_version + 1])->save();
            }

            foreach (Sample::whereIn('id', $work->pluck('sample_id')->filter()->unique())->get() as $sample) {
                app(Progress::class)->refreshSample($sample);
            }
        });
    }
}
