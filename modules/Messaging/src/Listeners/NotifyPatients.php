<?php

declare(strict_types=1);

namespace Modules\Messaging\Listeners;

use App\Core\Modules\ModuleListener;
use App\Core\Settings\Settings;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Laboratory\Models\WorkItem;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\Messenger;
use Modules\Messaging\Support\OrderValues;
use Modules\Messaging\Support\ReportLinks;
use Modules\Messaging\Support\Templates;
use Throwable;

/**
 * Tells the patient about their bill and their report. A problem here must
 * never stop the counter or the laboratory, so every failure is only reported.
 */
final class NotifyPatients extends ModuleListener
{
    protected function moduleKey(): string
    {
        return 'messaging';
    }

    protected function process(object $event): void
    {
        try {
            match (true) {
                $event instanceof OrderPlaced => $this->orderPlaced($event->order),
                $event instanceof ResultsReleased => $this->resultsReleased($event->workItem),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function orderPlaced(Order $order): void
    {
        if (! app(Settings::class)->get(Permissions::SETTING_NOTIFY_ORDER, true) || $order->patient === null) {
            return;
        }

        app(Messenger::class)->toPatient(
            $order->patient,
            Templates::ORDER_PLACED,
            app(OrderValues::class)->for($order),
            $order,
            "order_placed:{$order->id}",
        );
    }

    /** One message per order, sent when its last result is released. */
    private function resultsReleased(WorkItem $item): void
    {
        $settings = app(Settings::class);

        if (! $settings->get(Permissions::SETTING_NOTIFY_REPORT, true)) {
            return;
        }

        $waiting = WorkItem::where('order_id', $item->order_id)
            ->whereNotIn('status', [WorkStatus::Released->value, WorkStatus::Cancelled->value])
            ->exists();

        $order = Order::with('patient')->find($item->order_id);

        if ($waiting || $order?->patient === null) {
            return;
        }

        $messenger = app(Messenger::class);
        $dedupe = "report_ready:{$order->id}";
        $values = app(OrderValues::class)->for($order);

        // No link is made unless a message will really carry it.
        $willSend = Message::where('dedupe_key', 'like', $dedupe.':%')->doesntExist()
            && ($messenger->available(Channel::Sms) || $messenger->available(Channel::Email));

        if ($willSend && $settings->get(Permissions::SETTING_REPORT_LINK, true)) {
            $values['link'] = app(ReportLinks::class)->create($order);
        }

        $messenger->toPatient($order->patient, Templates::REPORT_READY, $values, $order, $dedupe);
    }
}
