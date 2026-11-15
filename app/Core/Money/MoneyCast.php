<?php

declare(strict_types=1);

namespace App\Core\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast between a DECIMAL(12,2) column and Money.
 *
 * @implements CastsAttributes<Money, Money|string|int>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value)) {
            return Money::fromDecimal($value);
        }

        // MySQL returns DECIMAL as a string and never reaches this line.
        // SQLite (fast tests only) returns a number, which is formatted
        // back to two places before parsing.
        return Money::fromDecimal(number_format((float) $value, 2, '.', ''));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->toDecimal();
        }

        if (is_string($value) || is_int($value)) {
            return Money::fromDecimal($value)->toDecimal();
        }

        throw new InvalidArgumentException("Money attribute [{$key}] must be a Money object, a decimal string, or a whole number.");
    }
}
