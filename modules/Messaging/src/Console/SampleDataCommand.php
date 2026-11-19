<?php

declare(strict_types=1);

namespace Modules\Messaging\Console;

use App\Core\Console\SampleDataCommand as Base;
use App\Core\Settings\Settings;
use App\Core\Tenancy\Clinic;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use Modules\Messaging\Actions\ManageMessages;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\Messenger;
use Modules\Messaging\Support\OrderValues;
use Modules\Messaging\Support\ReportLinks;
use Modules\Messaging\Support\Templates;
use RuntimeException;

/**
 * Sample data for the message log: SMS is switched on, and bill, report-ready
 * and due-reminder messages are made for the orders that already exist, as
 * they would have been. One message is shown as failed and one number is
 * opted out, so those screens have something to show.
 *
 * Run it after laboratory:sample-data. Messages go through the installation's
 * SMS setting: with the default "log" gateway nothing leaves the server, the
 * text is only written to the log. They show as "queued" until the queue
 * worker has run.
 */
final class SampleDataCommand extends Base
{
    protected $signature = 'messaging:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Switch SMS on and load sample bill, report-ready and due-reminder messages, for trying the message log';

    protected function moduleKey(): string
    {
        return 'messaging';
    }

    protected function load(Clinic $clinic): array
    {
        if (Message::query()->exists()) {
            throw new RuntimeException('This clinic already has messages. Sample data is only for a clinic without any.');
        }

        $orders = Order::with('patient')->orderByDesc('id')->get();

        if ($orders->isEmpty()) {
            throw new RuntimeException('There are no orders to send messages about. Run laboratory:sample-data first.');
        }

        app(Settings::class)->set(Permissions::SETTING_SMS_ENABLED, true);
        app(Templates::class)->ensureDefaults();

        $messenger = app(Messenger::class);
        $values = app(OrderValues::class);
        $counts = ['order_placed' => 0, 'report_ready' => 0, 'due_reminder' => 0];

        // Enough of each kind to fill the log: the newest bills, the first finished reports, the first dues.
        foreach ($orders->reverse() as $order) {
            if ($orders->search($order) < 10) {
                $counts['order_placed'] += count($messenger->toPatient($order->patient, Templates::ORDER_PLACED, $values->for($order), $order, "order_placed:{$order->id}"));
            }

            $work = WorkItem::where('order_id', $order->id)->whereNot('status', WorkStatus::Cancelled->value);
            $allReleased = (clone $work)->exists() && ! (clone $work)->where('status', '!=', WorkStatus::Released->value)->exists();

            if ($allReleased && $counts['report_ready'] < 8) {
                $withLink = $values->for($order);
                $withLink['link'] = app(ReportLinks::class)->create($order);
                $counts['report_ready'] += count($messenger->toPatient($order->patient, Templates::REPORT_READY, $withLink, $order, "report_ready:{$order->id}"));
            }

            $invoice = Invoice::where('order_id', $order->id)->first();

            if ($invoice !== null && $invoice->due_amount->isPositive() && $counts['due_reminder'] < 5) {
                $counts['due_reminder'] += count($messenger->toPatient($order->patient, Templates::DUE_REMINDER, $values->for($order, $invoice), $order, "due_reminder:{$order->id}:sample"));
            }
        }

        // One that the gateway did not accept, and one patient who asked to stop.
        $first = $orders->first();
        Message::create([
            'channel' => Channel::Sms,
            'recipient' => (string) $first->patient?->phone_normalized,
            'template_key' => Templates::DUE_REMINDER,
            'body' => 'Sample: this message could not be delivered.',
            'segments' => 1,
            'status' => MessageStatus::Failed,
            'provider' => 'sample',
            'error' => 'Sample failure: the SMS gateway did not answer.',
            'attempts' => 3,
            'dedupe_key' => "sample_failed:{$first->id}",
            'patient_id' => $first->patient?->id,
            'order_id' => $first->id,
            'created_by' => auth()->id(),
        ]);

        $optedOut = (string) $orders->last()->patient?->phone_normalized;

        if ($optedOut !== '') {
            app(ManageMessages::class)->stop($optedOut);
        }

        return [
            'SMS switched on for this clinic (Messaging settings)',
            "{$counts['order_placed']} bill confirmations, {$counts['report_ready']} report-ready messages with a patient link, {$counts['due_reminder']} due reminders (Message log)",
            '1 failed message and 1 number that asked to stop (Message log)',
            'Messages show as "queued" until the queue worker runs: php artisan queue:work --stop-when-empty',
        ];
    }
}
