<?php

declare(strict_types=1);

namespace Modules\Laboratory\Events;

use Modules\Laboratory\Models\WorkItem;

/** Raised after the change has been committed. */
final class ResultsReleased
{
    public function __construct(public readonly WorkItem $workItem) {}
}
