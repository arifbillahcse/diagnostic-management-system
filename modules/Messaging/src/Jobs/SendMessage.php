<?php

declare(strict_types=1);

namespace Modules\Messaging\Jobs;

use App\Core\Modules\ModuleState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Modules\Messaging\Drivers\SendResult;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Support\Messenger;
use Throwable;

/**
 * Sends one logged message. It never throws: a failure is written on the
 * message, where staff can see it and `messaging:retry` picks it up.
 * Running it twice does nothing the second time.
 */
final class SendMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $messageId) {}

    public function handle(Messenger $messenger, ModuleState $modules): void
    {
        if (! $modules->isEnabled('messaging')) {
            return;
        }

        // Claim the message first, so two workers cannot send it twice.
        $claimed = Message::whereKey($this->messageId)
            ->where('status', MessageStatus::Queued->value)
            ->whereNull('sent_at')
            ->where('attempts', '<', 10)
            ->increment('attempts');

        $message = $claimed === 1 ? Message::find($this->messageId) : null;

        if ($message === null) {
            return;
        }

        try {
            [$provider, $result] = $message->channel === Channel::Sms
                ? $this->sms($messenger, $message)
                : $this->email($message);
        } catch (Throwable $e) {
            report($e);
            [$provider, $result] = [null, SendResult::failed('Unexpected error while sending. See the server log.')];
        }

        $message->forceFill($result->ok
            ? ['status' => MessageStatus::Sent, 'provider' => $provider, 'provider_ref' => $result->reference, 'error' => null, 'sent_at' => now()]
            : ['status' => MessageStatus::Failed, 'provider' => $provider, 'error' => $result->error],
        )->save();
    }

    /** @return array{0: ?string, 1: SendResult} */
    private function sms(Messenger $messenger, Message $message): array
    {
        $driver = $messenger->smsDriver();

        return $driver === null
            ? [null, SendResult::failed('SMS is switched off for this installation.')]
            : [$driver->name(), $driver->send($message->recipient, $message->body)];
    }

    /** @return array{0: ?string, 1: SendResult} */
    private function email(Message $message): array
    {
        try {
            Mail::raw($message->body, function ($mail) use ($message): void {
                $mail->to($message->recipient)->subject((string) $message->subject);
            });
        } catch (Throwable $e) {
            return ['mail', SendResult::failed(mb_substr('The email could not be sent: '.$e->getMessage(), 0, 480))];
        }

        return ['mail', SendResult::sent()];
    }
}
