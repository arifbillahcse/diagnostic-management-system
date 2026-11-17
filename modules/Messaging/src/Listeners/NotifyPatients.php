<?php

declare(strict_types=1);

namespace Modules\Messaging\Listeners;

use App\Core\Modules\ModuleListener;
use App\Core\Settings\Settings;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Events\ReportSigned;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Laboratory\Models\WorkItem;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\ImagingReports;
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
                $event instanceof ResultsReleased => $this->reportsReady($event->workItem->order_id),
                $event instanceof ReportSigned => $this->reportsReady($event->study->order_id),
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

    /**
     * One message per order, sent when nothing on it is still waiting: every
     * lab test released and, with imaging on, every imaging report signed.
     */
    private function reportsReady(int $orderId): void
    {
        $settings = app(Settings::class);

        if (! $settings->get(Permissions::SETTING_NOTIFY_REPORT, true)) {
            return;
        }

        $waiting = WorkItem::where('order_id', $orderId)
            ->whereNotIn('status', [WorkStatus::Released->value, WorkStatus::Cancelled->value])
            ->exists()
            || (ImagingReports::on() && ImagingReports::waiting($orderId));

        $order = Order::with('patient')->find($orderId);

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
