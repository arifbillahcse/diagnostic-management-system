<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

/**
 * Exact handling of lab values with up to six decimal places.
 *
 * A value is held as a whole number of millionths, so comparing it with a
 * reference limit never involves floating point. A haemoglobin of 11.5
 * against a low limit of 11.5 is equal, always.
 */
final class Decimal
{
    private const PATTERN = '/^([+-])?(\d{1,12})(?:\.(\d{1,6}))?$/';

    /** Whether text typed by a user is a number this class accepts. */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, trim($value)) === 1;
    }

    /**
     * Millionths of a typed number or a database DECIMAL, or null for an
     * empty value.
     */
    public static function micro(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            // Only SQLite (fast tests) returns DECIMAL columns as floats.
            $value = number_format($value, 6, '.', '');
        }

        if (! preg_match(self::PATTERN, trim((string) $value), $m)) {
            return null;
        }

        $micro = ((int) $m[2]) * 1_000_000 + (int) str_pad($m[3] ?? '', 6, '0');

        return ($m[1] ?? '') === '-' ? -$micro : $micro;
    }

    /** A value in millionths as text with a fixed number of decimals, rounded half up. */
    public static function format(int $micro, int $decimals): string
    {
        $decimals = max(0, min(6, $decimals));
        $step = 10 ** (6 - $decimals);
        $rounded = intdiv(abs($micro) + intdiv($step, 2), $step);
        $scale = 10 ** $decimals;

        $text = (string) intdiv($rounded, $scale);

        if ($decimals > 0) {
            $text .= '.'.str_pad((string) ($rounded % $scale), $decimals, '0', STR_PAD_LEFT);
        }

        return ($micro < 0 && $rounded !== 0 ? '-' : '').$text;
    }

    /** The shortest exact text: 13.5, not 13.500000. Used to refill an input. */
    public static function plain(int $micro): string
    {
        $text = self::format($micro, 6);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Plain decimal text for storing in a DECIMAL(18,6) column. */
    public static function toStorage(int $micro): string
    {
        return self::format($micro, 6);
    }
}
