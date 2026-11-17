<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Core\Money\Money;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact quantity of stock with up to three decimals (2.5 ml, 0.125 box),
 * held as a whole number of thousandths. Never a float.
 */
final readonly class Quantity implements JsonSerializable, Stringable
{
    private function __construct(public int $milli) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMilli(int $milli): self
    {
        return new self($milli);
    }

    public static function fromDecimal(string|int $value): self
    {
        $value = trim((string) $value);

        if (! preg_match('/^(-?)(\d{1,11})(?:\.(\d{1,3}))?$/', $value, $m)) {
            throw new InvalidArgumentException("[{$value}] is not a quantity. Use a number with up to three decimals.");
        }

        $milli = (int) $m[2] * 1000 + (int) str_pad($m[3] ?? '', 3, '0');

        return new self($m[1] === '-' ? -$milli : $milli);
    }

    public function plus(self $other): self
    {
        return new self($this->milli + $other->milli);
    }

    public function minus(self $other): self
    {
        return new self($this->milli - $other->milli);
    }

    public function negate(): self
    {
        return new self(-$this->milli);
    }

    public function abs(): self
    {
        return new self(abs($this->milli));
    }

    public function isZero(): bool
    {
        return $this->milli === 0;
    }

    public function isPositive(): bool
    {
        return $this->milli > 0;
    }

    public function isNegative(): bool
    {
        return $this->milli < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->milli > $other->milli;
    }

    public function lessThan(self $other): bool
    {
        return $this->milli < $other->milli;
    }

    public function equals(self $other): bool
    {
        return $this->milli === $other->milli;
    }

    /** What this many units cost at a unit price, rounded half up to the poisha. */
    public function costAt(Money $unitPrice): Money
    {
        return Money::fromMinor(self::roundDiv($unitPrice->minor * $this->milli, 1000));
    }

    /** This quantity's share of a value held for a larger quantity (the average cost of what is taken out). */
    public function shareOf(Money $value, self $of): Money
    {
        if ($of->milli === 0) {
            return Money::zero();
        }

        return Money::fromMinor(self::roundDiv($value->minor * $this->milli, $of->milli));
    }

    /** Three decimals, as stored: "12.500". */
    public function toDecimal(): string
    {
        $sign = $this->milli < 0 ? '-' : '';
        $abs = abs($this->milli);

        return $sign.intdiv($abs, 1000).'.'.str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** For people: "12.5", "100", "0.125". */
    public function formatted(): string
    {
        return rtrim(rtrim($this->toDecimal(), '0'), '.');
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    private static function roundDiv(int $numerator, int $denominator): int
    {
        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);
        $result = intdiv($n, $d) + ((($n % $d) * 2) >= $d ? 1 : 0);

        return $negative ? -$result : $result;
    }
}
