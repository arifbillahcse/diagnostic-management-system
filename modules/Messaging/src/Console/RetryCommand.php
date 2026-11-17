<?php

declare(strict_types=1);

namespace Modules\Messaging\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Jobs\SendMessage;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\Messaging\Support\Messenger;

final class RetryCommand extends Command
{
    /** A message is tried this many times in all before it is left for staff. */
    public const MAX_ATTEMPTS = 3;

    protected $signature = 'messaging:retry {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Try again the messages that failed in the last two hours, and send any left waiting. Scheduled every ten minutes';

    public function handle(ClinicManager $manager): int
    {
        $retried = 0;

        $manager->eachClinic(function () use (&$retried): void {
            if (app(ModuleState::class)->isEnabled('messaging')) {
                $retried += self::retry();
            }
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$retried} message(s) sent to the queue again.");

        return self::SUCCESS;
    }

    public static function retry(): int
    {
        $messenger = app(Messenger::class);
        $count = 0;

        Message::query()
            ->where('created_at', '>=', now()->subHours(2))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query
                ->where('status', MessageStatus::Failed->value)
                // Queued but never picked up, for example the queue was cleared.
                ->orWhere(fn ($q) => $q->where('status', MessageStatus::Queued->value)->where('updated_at', '<=', now()->subMinutes(10))))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (Message $message) use ($messenger, &$count): void {
                if (! $messenger->available($message->channel) || OptOut::where('recipient', $message->recipient)->exists()) {
                    return;
                }

                $message->forceFill(['status' => MessageStatus::Queued])->save();
                SendMessage::dispatch($message->id);
                $count++;
            });

        return $count;
    }
}
