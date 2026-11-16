<?php

declare(strict_types=1);

namespace Modules\Laboratory\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\Laboratory\Support\WorkPlanner;

/** Creates laboratory work for the lab tests on an order. */
final class PlanWorkForAddedItem extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'laboratory';
    }

    protected function process(object $event): void
    {
        app(WorkPlanner::class)->planFor($event->item->order);
    }
}
