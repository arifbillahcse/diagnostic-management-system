<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use App\Core\Patients\Patient;
use App\Core\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Models\Order;
use Modules\Messaging\Drivers\HttpSmsDriver;
use Modules\Messaging\Drivers\LogSmsDriver;
use Modules\Messaging\Drivers\SmsDriver;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Jobs\SendMessage;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\Messaging\Permissions;

/**
 * Puts messages in the log and hands them to the queue. Nothing is sent
 * during the request itself, so a slow SMS gateway never slows the counter.
 */
final class Messenger
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Templates $templates,
    ) {}

    /** The installation's SMS gateway, or null when SMS is switched off for the whole installation. */
    public function smsDriver(): ?SmsDriver
    {
        $config = (array) config('dms.messaging.sms', []);

        return match ($config['driver'] ?? 'log') {
            'http' => new HttpSmsDriver($config),
            'log' => new LogSmsDriver,
            default => null,
        };
    }

    public function available(Channel $channel): bool
    {
        return match ($channel) {
            Channel::Sms => (bool) $this->settings->get(Permissions::SETTING_SMS_ENABLED, false) && $this->smsDriver() !== null,
            Channel::Email => (bool) $this->settings->get(Permissions::SETTING_EMAIL_ENABLED, false),
        };
    }

    /**
     * Send one kind of message to a patient, by SMS and by email where each
     * is switched on and the patient has a number or an address.
     *
     * @param  array<string, string>  $values
     * @param  string  $dedupe  the same value never sends twice on one channel
     * @return list<Message>
     */
    public function toPatient(Patient $patient, string $template, array $values, ?Order $order, string $dedupe): array
    {
        $sent = [];
        $phone = (string) $patient->phone_normalized;

        if (preg_match('/^8801[3-9]\d{8}$/', $phone)) {
            $sent[] = $this->queue(Channel::Sms, $phone, $template, $values, $patient, $order, $dedupe);
        }

        if (filter_var((string) $patient->email, FILTER_VALIDATE_EMAIL)) {
            $sent[] = $this->queue(Channel::Email, strtolower((string) $patient->email), $template, $values, $patient, $order, $dedupe);
        }

        return array_values(array_filter($sent));
    }

    /**
     * @param  array<string, string>  $values
     * @return ?Message null when nothing was queued: channel off, opted out, switched-off template, or already sent
     */
    public function queue(Channel $channel, string $recipient, string $template, array $values, ?Patient $patient = null, ?Order $order = null, ?string $dedupe = null): ?Message
    {
        if (! $this->available($channel) || OptOut::where('recipient', $recipient)->exists()) {
            return null;
        }

        $text = $template === Templates::TEST
            ? ['subject' => 'Test message', 'body' => 'Test message from '.($values['clinic'] ?? 'the clinic').'. Messaging works.']
            : $this->templates->render($template, $channel, $values);

        if ($text === null || $text['body'] === '') {
            return null;
        }

        try {
            $message = Message::create([
                'channel' => $channel,
                'recipient' => $recipient,
                'template_key' => $template,
                'subject' => $text['subject'],
                'body' => $text['body'],
                'segments' => $channel === Channel::Sms ? SmsText::segments($text['body']) : 1,
                'status' => MessageStatus::Queued,
                'dedupe_key' => $dedupe === null ? null : mb_substr($dedupe.':'.$channel->value, 0, 120),
                'patient_id' => $patient?->id,
                'order_id' => $order?->id,
                'created_by' => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // this event already produced this message
        }

        SendMessage::dispatch($message->id)->afterCommit();

        return $message;
    }

    /** @return array<string, string> the values every message can use */
    public function clinicValues(): array
    {
        return [
            'clinic' => (string) $this->settings->get('clinic.name'),
            'phone' => (string) $this->settings->get('clinic.phone'),
        ];
    }
}
