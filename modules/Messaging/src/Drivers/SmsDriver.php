<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

/**
 * One way of sending an SMS. A new provider is a new class with this
 * interface; nothing else in the system changes.
 */
interface SmsDriver
{
    /** A short name stored with each message, for example "http". */
    public function name(): string;

    /** @param string $to the number in international form without "+", for example 8801712345678 */
    public function send(string $to, string $text): SendResult;
}
