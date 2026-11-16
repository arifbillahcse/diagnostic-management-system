<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\OrderItem;

/**
 * Raised inside the cancelling transaction, before anything changes.
 *
 * A listener that must stop the cancellation throws a FrontDeskException
 * with a message staff can read. Package components get their own event
 * only when a whole order is cancelled; cancelling a package raises it for
 * the package item, and listeners should look at its components too.
 */
final class OrderItemCancelling
{
    public function __construct(public readonly OrderItem $item) {}
}
