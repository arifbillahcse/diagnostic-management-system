<?php

declare(strict_types=1);

namespace Modules\Laboratory\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;

/**
 * Stops the front desk cancelling a test once the laboratory has results
 * for it. Runs inside the cancelling transaction.
 */
final class GuardItemCancellation extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'laboratory';
    }

    protected function process(object $event): void
    {
        $itemIds = [$event->item->id, ...OrderItem::where('package_item_of', $event->item->id)->pluck('id')->all()];

        $started = WorkItem::query()
            ->whereIn('order_item_id', $itemIds)
            ->whereNotIn('status', [WorkStatus::AwaitingSample->value, WorkStatus::Ready->value, WorkStatus::Cancelled->value])
            ->pluck('test_name');

        if ($started->isNotEmpty()) {
            throw new FrontDeskException('The laboratory already has results for '.$started->implode(', ').'. It cannot be cancelled any more.');
        }

        $drafts = WorkItem::query()
            ->whereIn('order_item_id', $itemIds)
            ->where('status', WorkStatus::Ready->value)
            ->whereHas('results')
            ->pluck('test_name');

        if ($drafts->isNotEmpty()) {
            throw new FrontDeskException('The laboratory has started entering results for '.$drafts->implode(', ').'. Ask the laboratory before cancelling.');
        }
    }
}
