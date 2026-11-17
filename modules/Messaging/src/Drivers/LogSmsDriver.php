<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * Test mode: nothing leaves the server. The message is recorded in the
 * message log as sent, so screens and wording can be tried without paying
 * for SMS. The text itself is not written to the server log, because it
 * holds patient details.
 */
final class LogSmsDriver implements SmsDriver
{
    public function name(): string
    {
        return 'test';
    }

    public function send(string $to, string $text): SendResult
    {
        Log::info('SMS (test mode, not sent)', ['to' => substr($to, 0, 5).'*****'.substr($to, -3), 'length' => mb_strlen($text)]);

        return SendResult::sent('test-mode');
    }
}
