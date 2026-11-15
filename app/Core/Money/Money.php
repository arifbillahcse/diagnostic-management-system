<?php

declare(strict_types=1);

namespace App\Core\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact amount of money in taka.
 *
 * The amount is held as a whole number of poisha (1/100 taka), so no
 * calculation ever touches floating point. Rounding happens in one place:
 * roundHalfUp().
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $minor) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * Parse a decimal string such as "1250", "1250.5", or "-10.25".
     * More than two decimal places is refused, never silently rounded.
     */
    public static function fromDecimal(string|int $amount): self
    {
        if (is_int($amount)) {
            return new self($amount * 100);
        }

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $minor = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return new self(($m[1] ?? '') === '-' ? -$minor : $minor);
    }

    public static function sum(self ...$amounts): self
    {
        return new self(array_sum(array_map(fn (self $a): int => $a->minor, $amounts)));
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity);
    }

    public function negate(): self
    {
        return new self(-$this->minor);
    }

    /**
     * A percentage of this amount, rounded half up to the poisha.
     * The percentage may have up to four decimal places, for example "7.5".
     */
    public function percent(string|int $percent): self
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', trim((string) $percent), $m)) {
            throw new InvalidArgumentException("Invalid percentage [{$percent}].");
        }

        $scaled = ((int) $m[1]) * 10_000 + (int) str_pad($m[2] ?? '', 4, '0');

        return new self(self::roundHalfUp($this->minor * $scaled, 1_000_000));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        return $this->minor < $other->minor;
    }

    /** Decimal string with exactly two places, for example "1250.50". */
    public function toDecimal(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    /** Integer division rounded half away from zero. */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);

        return $numerator < 0 ? -$quotient : $quotient;
    }
}
