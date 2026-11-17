<?php

declare(strict_types=1);

namespace Modules\Laboratory\Enums;

/**
 * Sample state machine. See docs/schema.md, section 7.
 */
enum SampleStatus: string
{
    case Pending = 'pending';       // to be collected
    case Collected = 'collected';   // taken from the patient
    case InTransit = 'in_transit';  // on its way from a collection centre to the laboratory
    case Received = 'received';     // in the laboratory
    case InProcess = 'in_process';  // results being entered
    case Completed = 'completed';   // every test on it is released
    case Rejected = 'rejected';     // unusable; collect again
    case Cancelled = 'cancelled';   // every test on it was cancelled

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'To collect',
            self::Collected => 'Collected',
            self::InTransit => 'On the way to the lab',
            self::Received => 'In the lab',
            self::InProcess => 'In process',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending, self::Rejected => [self::Collected, self::Received, self::Cancelled],
            self::Collected => [self::InTransit, self::Received, self::Rejected, self::Cancelled],
            self::InTransit => [self::Received, self::Rejected, self::Cancelled],
            self::Received => [self::InProcess, self::Completed, self::Rejected, self::Cancelled],
            self::InProcess => [self::Completed, self::Rejected, self::Cancelled],
            self::Completed => [self::InProcess],
            self::Cancelled => [],
        }, true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c): array => [$c->value, $c->label()], self::cases()), 1, 0);
    }
}
