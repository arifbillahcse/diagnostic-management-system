<?php

declare(strict_types=1);

namespace Modules\Laboratory\Enums;

/**
 * State machine of one test to be performed. See docs/schema.md, section 7.
 */
enum WorkStatus: string
{
    case AwaitingSample = 'awaiting_sample';
    case Ready = 'ready';         // sample is in the lab; results can be entered
    case Entered = 'entered';     // submitted, waiting for verification
    case Verified = 'verified';   // checked and signed off
    case Released = 'released';   // available to print for the patient
    case Amending = 'amending';   // a released result is being corrected
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingSample => 'Waiting for sample',
            self::Ready => 'To enter',
            self::Entered => 'To verify',
            self::Verified => 'Verified',
            self::Released => 'Released',
            self::Amending => 'Being amended',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::AwaitingSample => [self::Ready, self::Cancelled],
            self::Ready => [self::Entered, self::AwaitingSample, self::Cancelled],
            self::Entered => [self::Verified, self::Ready, self::Amending, self::AwaitingSample, self::Cancelled],
            self::Verified => [self::Released],
            self::Released => [self::Amending],
            self::Amending => [self::Entered],
            self::Cancelled => [],
        }, true);
    }

    /** Values may be typed in. */
    public function isEditable(): bool
    {
        return $this === self::Ready || $this === self::Amending;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c): array => [$c->value, $c->label()], self::cases()), 1, 0);
    }
}
