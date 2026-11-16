<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Actions\AdvanceOrder;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\SampleEvent;
use Modules\Laboratory\Models\WorkItem;

/**
 * Keeps a sample's status and its order's status in step with the state of
 * the tests on them.
 */
final class Progress
{
    public function __construct(private readonly AdvanceOrder $orders) {}

    public function refreshSample(?Sample $sample): void
    {
        if ($sample === null) {
            return;
        }

        $statuses = $sample->workItems()->pluck('status')->map(fn ($s): WorkStatus => $s instanceof WorkStatus ? $s : WorkStatus::from($s));
        $live = $statuses->reject(fn (WorkStatus $s): bool => $s === WorkStatus::Cancelled);

        $target = match (true) {
            $live->isEmpty() => SampleStatus::Cancelled,
            $live->every(fn (WorkStatus $s): bool => $s === WorkStatus::Released) => SampleStatus::Completed,
            $live->contains(fn (WorkStatus $s): bool => ! in_array($s, [WorkStatus::AwaitingSample, WorkStatus::Ready], true)),
            // A saved draft also counts as work in progress.
            Result::whereIn('work_item_id', $sample->workItems()->select('id'))->exists() => SampleStatus::InProcess,
            default => null,
        };

        if ($target !== null && $target !== $sample->status && $sample->status->canBecome($target)) {
            $sample->forceFill(['status' => $target])->save();
            SampleEvent::create(['sample_id' => $sample->id, 'event' => $target->value, 'actor_id' => Auth::id(), 'occurred_at' => now()]);
        }
    }

    /** Work has started on the order. */
    public function started(Order $order): void
    {
        $this->orders->to($order, OrderStatus::InProgress);
    }

    /**
     * Complete the order when every lab test on it is released. An order
     * that also holds imaging is left for the imaging module to complete.
     */
    public function refreshOrder(Order $order): void
    {
        $active = $order->items()->where('status', ItemStatus::Active->value)->get();

        if ($active->contains(fn ($item): bool => $item->service_type === ServiceType::Imaging)) {
            return;
        }

        $labItems = $active->where('service_type', ServiceType::LabTest);

        if ($labItems->isEmpty()) {
            return;
        }

        $released = WorkItem::query()
            ->whereIn('order_item_id', $labItems->pluck('id'))
            ->where('status', WorkStatus::Released->value)
            ->count();

        if ($released === $labItems->count()) {
            $this->orders->to($order, OrderStatus::Completed);
        }
    }
}
