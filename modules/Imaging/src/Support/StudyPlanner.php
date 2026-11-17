<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Models\Study;

/**
 * One imaging study for every active imaging test on an order, including
 * imaging tests inside a package. Safe to run again: it only adds what is
 * missing.
 */
final class StudyPlanner
{
    /** @return int how many studies were created */
    public function planFor(Order $order): int
    {
        return DB::connection('clinic')->transaction(function () use ($order): int {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $items = $order->items()
                ->where('status', ItemStatus::Active->value)
                ->where('service_type', ServiceType::Imaging->value)
                ->whereNotIn('id', Study::query()->select('order_item_id')->where('order_id', $order->id))
                ->get();

            foreach ($items as $item) {
                Study::create([
                    'order_item_id' => $item->id,
                    'order_id' => $order->id,
                    'patient_id' => $order->patient_id,
                    'branch_id' => $order->branch_id,
                    'service_id' => $item->service_id,
                    'study_name' => $item->service_name,
                    'status' => 'pending',
                ]);
            }

            return $items->count();
        });
    }
}
