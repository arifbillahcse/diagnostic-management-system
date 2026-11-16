<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Models\Order;

/**
 * Moves an order forward as work on it progresses. Called by modules that
 * do the work (the laboratory), not by a person, so it carries no
 * permission check of its own; the calling action has already checked.
 *
 * A move the state machine does not allow is ignored, not an error: an
 * order that is already completed stays completed.
 */
final class AdvanceOrder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function to(Order $order, OrderStatus $next): bool
    {
        return DB::connection('clinic')->transaction(function () use ($order, $next): bool {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === $next || $next === OrderStatus::Cancelled || ! $order->status->canBecome($next)) {
                return false;
            }

            $previous = $order->status;
            $order->forceFill(['status' => $next])->save();

            $this->audit->record('order.status_changed', $order, ['status' => $previous->value], ['status' => $next->value]);

            return true;
        });
    }
}
