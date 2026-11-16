<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Enums;

/**
 * Order state machine. See docs/schema.md, section 7.
 *
 * The front desk creates orders as confirmed. The laboratory module moves
 * them to in progress and completed.
 */
enum OrderStatus: string
{
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Confirmed => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        }, true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
