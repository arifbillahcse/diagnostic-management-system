<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\Order;

/** Raised after the change has been committed. */
final class OrderCancelled
{
    public function __construct(public readonly Order $order) {}
}
