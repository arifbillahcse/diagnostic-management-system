<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\OrderItem;

/** Raised after the change has been committed. */
final class OrderItemAdded
{
    public function __construct(public readonly OrderItem $item) {}
}
