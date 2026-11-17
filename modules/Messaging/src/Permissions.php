<?php

declare(strict_types=1);

namespace Modules\Messaging;

/**
 * Permission and setting keys of the messaging module.
 */
final class Permissions
{
    public const LOG_VIEW = 'messaging.log.view';

    /** Send a failed message again, and stop or allow messages to a number. */
    public const MESSAGES_MANAGE = 'messaging.messages.manage';

    public const TEMPLATES_MANAGE = 'messaging.templates.manage';

    public const SETTINGS_MANAGE = 'messaging.settings.manage';

    public const SETTING_SMS_ENABLED = 'messaging.sms_enabled';

    public const SETTING_EMAIL_ENABLED = 'messaging.email_enabled';

    /** Tell the patient the bill total after an order is placed. */
    public const SETTING_NOTIFY_ORDER = 'messaging.notify_order';

    /** Tell the patient when every result of an order is ready. */
    public const SETTING_NOTIFY_REPORT = 'messaging.notify_report';

    /** Put a download link in the "report ready" message. */
    public const SETTING_REPORT_LINK = 'messaging.report_link';

    /** How many days a download link works. */
    public const SETTING_LINK_DAYS = 'messaging.link_days';

    /** Remind about an unpaid bill every this many days. 0 switches reminders off. */
    public const SETTING_DUE_REMINDER_DAYS = 'messaging.due_reminder_days';

    /** A bill is reminded about at most this many times. */
    public const DUE_REMINDER_LIMIT = 3;

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::LOG_VIEW => 'View the log of messages sent to patients',
            self::MESSAGES_MANAGE => 'Send a failed message again, and stop messages to a number',
            self::TEMPLATES_MANAGE => 'Change the wording of messages',
            self::SETTINGS_MANAGE => 'Change messaging settings',
        ];
    }
}
