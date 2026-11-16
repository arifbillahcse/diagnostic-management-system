<?php

declare(strict_types=1);

namespace Modules\Laboratory\Events;

use Modules\Laboratory\Models\Sample;

/** Raised after the change has been committed. */
final class SampleCollected
{
    public function __construct(public readonly Sample $sample) {}
}
