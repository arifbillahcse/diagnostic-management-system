<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Models\Template;

/**
 * The wording of each message. The clinic can change it; until it does, the
 * built-in text below is used.
 *
 * Placeholders, written {like_this}: clinic, patient, bill_no, total, paid,
 * due, delivery, link, phone.
 */
final class Templates
{
    public const ORDER_PLACED = 'order_placed';

    public const REPORT_READY = 'report_ready';

    public const DUE_REMINDER = 'due_reminder';

    public const TEST = 'test';

    /** @return array<string, array{name: string, sms: string, subject: string, email: string}> */
    public static function defaults(): array
    {
        return [
            self::ORDER_PLACED => [
                'name' => 'Bill confirmation',
                'sms' => 'Dear {patient}, your bill {bill_no} at {clinic} is Tk {total}. Report ready: {delivery}. Call {phone} for help.',
                'subject' => 'Your bill {bill_no} at {clinic}',
                'email' => "Dear {patient},\n\nYour bill {bill_no} at {clinic} is Tk {total}.\nReport ready: {delivery}.\n\nFor help call {phone}.\n\n{clinic}",
            ],
            self::REPORT_READY => [
                'name' => 'Report ready',
                'sms' => 'Dear {patient}, your report for bill {bill_no} is ready at {clinic}. {link}',
                'subject' => 'Your report is ready ({bill_no})',
                'email' => "Dear {patient},\n\nYour report for bill {bill_no} is ready.\n{link}\n\n{clinic}\n{phone}",
            ],
            self::DUE_REMINDER => [
                'name' => 'Due reminder',
                'sms' => 'Dear {patient}, Tk {due} is due on bill {bill_no} at {clinic}. Please pay at the counter. Call {phone} for help.',
                'subject' => 'Payment due on bill {bill_no}',
                'email' => "Dear {patient},\n\nTk {due} is still due on bill {bill_no} at {clinic}.\nPlease pay at the counter.\n\n{clinic}\n{phone}",
            ],
        ];
    }

    /** Make sure the clinic has a row for every template, so the screen can list and edit them. */
    public function ensureDefaults(): void
    {
        foreach (self::defaults() as $key => $default) {
            foreach (Channel::cases() as $channel) {
                Template::firstOrCreate(
                    ['template_key' => $key, 'channel' => $channel->value],
                    [
                        'subject' => $channel === Channel::Email ? $default['subject'] : null,
                        'body' => $default[$channel->value],
                    ],
                );
            }
        }
    }

    /**
     * The text to send, or null when the clinic switched this message off.
     *
     * @param  array<string, string>  $values  placeholder name => value
     * @return array{subject: ?string, body: string}|null
     */
    public function render(string $key, Channel $channel, array $values): ?array
    {
        $default = self::defaults()[$key] ?? null;
        $custom = Template::where('template_key', $key)->where('channel', $channel->value)->first();

        if ($custom !== null && ! $custom->is_active) {
            return null;
        }

        $body = $custom?->body ?? $default[$channel->value] ?? null;

        if ($body === null) {
            return null;
        }

        $subject = $channel === Channel::Email ? ($custom?->subject ?: ($default['subject'] ?? null)) : null;
        $pairs = [];

        foreach ($values as $name => $value) {
            $pairs['{'.$name.'}'] = $value;
        }

        // A placeholder with no value leaves no gap: "ready. {link}" becomes "ready."
        $fill = fn (string $text): string => trim((string) preg_replace('/[ \t]{2,}/', ' ', (string) preg_replace('/\{[a-z_]+\}/', '', strtr($text, $pairs))));

        return ['subject' => $subject === null ? null : $fill($subject), 'body' => $fill($body)];
    }

    public static function name(string $key): string
    {
        return self::defaults()[$key]['name'] ?? ($key === self::TEST ? 'Test message' : $key);
    }
}
