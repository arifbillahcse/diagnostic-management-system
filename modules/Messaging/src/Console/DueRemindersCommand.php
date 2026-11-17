<?php

declare(strict_types=1);

namespace Modules\Messaging\Console;

use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\Messenger;
use Modules\Messaging\Support\OrderValues;
use Modules\Messaging\Support\Templates;

final class DueRemindersCommand extends Command
{
    protected $signature = 'messaging:due-reminders {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Remind patients about unpaid bills, for clinics that switched reminders on. Scheduled daily';

    public function handle(ClinicManager $manager): int
    {
        $queued = 0;

        $manager->eachClinic(function () use (&$queued): void {
            if (app(ModuleState::class)->isEnabled('messaging')) {
                $queued += self::remind();
            }
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$queued} reminder(s) queued.");

        return self::SUCCESS;
    }

    /** Run for the active clinic. Returns how many reminders were queued. */
    public static function remind(): int
    {
        $every = (int) app(Settings::class)->get(Permissions::SETTING_DUE_REMINDER_DAYS, 0);

        if ($every < 1) {
            return 0;
        }

        $messenger = app(Messenger::class);
        $values = app(OrderValues::class);
        $queued = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_amount', '>', 0)
            ->where('issued_at', '<=', now()->subDays($every))
            // Old debts are a matter for a phone call, not an endless stream of SMS.
            ->where('issued_at', '>=', now()->subDays($every * (Permissions::DUE_REMINDER_LIMIT + 1)))
            ->with(['order.patient'])
            ->chunkById(200, function ($invoices) use ($messenger, $values, $every, &$queued): void {
                foreach ($invoices as $invoice) {
                    $order = $invoice->order;

                    if ($order === null || $order->patient === null || $order->status === OrderStatus::Cancelled) {
                        continue;
                    }

                    // The first reminder is number 1, the next comes "every" days later.
                    $number = intdiv((int) $invoice->issued_at->diffInDays(now()), $every);

                    if ($number < 1 || $number > Permissions::DUE_REMINDER_LIMIT) {
                        continue;
                    }

                    $queued += count($messenger->toPatient(
                        $order->patient,
                        Templates::DUE_REMINDER,
                        $values->for($order, $invoice),
                        $order,
                        "due:{$invoice->id}:{$number}",
                    ));
                }
            });

        return $queued;
    }
}
