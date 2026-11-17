<?php

declare(strict_types=1);

namespace Modules\Imaging\Listeners;

use App\Core\Modules\ModuleListener;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Imaging\Support\StudyPlanner;

/** Adds an imaging study for each imaging test billed or added. */
final class PlanStudies extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'imaging';
    }

    protected function process(object $event): void
    {
        app(StudyPlanner::class)->planFor($event instanceof OrderPlaced ? $event->order : $event->item->order);
    }
}
