<?php

declare(strict_types=1);

namespace Modules\Messaging\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'];
    }
}
