<?php

declare(strict_types=1);

namespace Modules\Laboratory\Enums;

/**
 * How a result compares with its reference range. Always calculated by the
 * system; a user cannot set or change it.
 */
enum Flag: string
{
    case None = 'none';       // no range to compare with, or not a comparable value
    case Normal = 'normal';
    case Low = 'low';
    case High = 'high';
    case CriticalLow = 'critical_low';
    case CriticalHigh = 'critical_high';
    case Abnormal = 'abnormal'; // a choice marked abnormal

    public function label(): string
    {
        return match ($this) {
            self::None, self::Normal => '',
            self::Low => 'Low',
            self::High => 'High',
            self::CriticalLow => 'Critical low',
            self::CriticalHigh => 'Critical high',
            self::Abnormal => 'Abnormal',
        };
    }

    /** Short mark printed beside the value on a report. */
    public function mark(): string
    {
        return match ($this) {
            self::None, self::Normal => '',
            self::Low => 'L',
            self::High => 'H',
            self::CriticalLow => 'LL',
            self::CriticalHigh => 'HH',
            self::Abnormal => 'A',
        };
    }

    public function isAbnormal(): bool
    {
        return ! in_array($this, [self::None, self::Normal], true);
    }

    public function isCritical(): bool
    {
        return $this === self::CriticalLow || $this === self::CriticalHigh;
    }
}
