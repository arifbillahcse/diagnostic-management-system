<?php

declare(strict_types=1);

namespace Modules\Messaging\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return $this === self::Sms ? 'SMS' : 'Email';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [self::Sms->value => 'SMS', self::Email->value => 'Email'];
    }
}
