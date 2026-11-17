<?php

declare(strict_types=1);

namespace Modules\Imaging\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\Imaging\Models\Study;

/**
 * Stops the front desk cancelling an imaging test once its report has been
 * started. Runs inside the cancelling transaction.
 */
final class GuardCancellation extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'imaging';
    }

    protected function process(object $event): void
    {
        $itemIds = [$event->item->id, ...OrderItem::where('package_item_of', $event->item->id)->pluck('id')->all()];

        $started = Study::whereIn('order_item_id', $itemIds)->whereIn('status', ['draft', 'final', 'amending'])->pluck('study_name');

        if ($started->isNotEmpty()) {
            throw new FrontDeskException('The report for '.$started->implode(', ').' has been started. It cannot be cancelled any more.');
        }
    }
}
