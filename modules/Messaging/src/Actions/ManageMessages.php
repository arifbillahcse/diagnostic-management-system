<?php

declare(strict_types=1);

namespace Modules\Messaging\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Patients\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Jobs\SendMessage;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\MessagingException;
use Modules\Messaging\Support\Messenger;
use Modules\Messaging\Support\Templates;

/**
 * What staff can do with messages by hand.
 */
final class ManageMessages
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly AuditLogger $audit,
    ) {}

    /** Try a failed message again. */
    public function resend(Message $message): Message
    {
        Gate::authorize(Permissions::MESSAGES_MANAGE);

        if ($message->status !== MessageStatus::Failed) {
            throw new MessagingException('Only a failed message can be sent again.');
        }

        if (OptOut::where('recipient', $message->recipient)->exists()) {
            throw new MessagingException('Messages to this number or address have been stopped.');
        }

        if (! $this->messenger->available($message->channel)) {
            throw new MessagingException($message->channel->label().' is switched off in messaging settings.');
        }

        $message->forceFill(['status' => MessageStatus::Queued, 'error' => null])->save();
        SendMessage::dispatch($message->id);

        return $message->refresh();
    }

    /** Stop every future message to a number or address. */
    public function stop(string $recipient): void
    {
        Gate::authorize(Permissions::MESSAGES_MANAGE);

        DB::connection('clinic')->transaction(function () use ($recipient): void {
            if (OptOut::firstOrCreate(['recipient' => $recipient], ['created_by' => Auth::id()])->wasRecentlyCreated) {
                $this->audit->record('messaging.stopped', null, [], ['recipient' => $recipient]);
            }
        });
    }

    public function allow(string $recipient): void
    {
        Gate::authorize(Permissions::MESSAGES_MANAGE);

        DB::connection('clinic')->transaction(function () use ($recipient): void {
            if (OptOut::where('recipient', $recipient)->delete() > 0) {
                $this->audit->record('messaging.allowed', null, [], ['recipient' => $recipient]);
            }
        });
    }

    /** Send a test SMS, to check the gateway and the clinic's settings. */
    public function sendTest(string $phone): Message
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $number = (string) PhoneNumber::normalize($phone);

        if (! preg_match('/^8801[3-9]\d{8}$/', $number)) {
            throw new MessagingException('Enter a Bangladeshi mobile number, for example 01712345678.');
        }

        if (! $this->messenger->available(Channel::Sms)) {
            throw new MessagingException('SMS is switched off. Switch it on and save first.');
        }

        return $this->messenger->queue(Channel::Sms, $number, Templates::TEST, $this->messenger->clinicValues())
            ?? throw new MessagingException('Messages to this number have been stopped.');
    }
}
