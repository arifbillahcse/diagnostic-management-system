<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Where a clinic's subscription stands. Licence::refresh() moves a clinic
 * between trial, active, overdue and read-only from its dates; suspended
 * and closed are set by the platform only.
 *
 *   trial / active → overdue (grace days) → read_only → active (when paid)
 */
enum ClinicStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Overdue = 'overdue';      // past the end date, inside the grace days: everything works
    case ReadOnly = 'read_only';   // after the grace days: look and print only
    case Suspended = 'suspended';  // stopped by the platform: not served at all
    case Closed = 'closed';

    /** The clinic's address is served (signing in, the portal, reports). */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::Overdue, self::ReadOnly], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Overdue => 'Payment overdue',
            self::ReadOnly => 'Read-only (unpaid)',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /** @return list<string> */
    public static function usableValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isUsable())));
    }
}
