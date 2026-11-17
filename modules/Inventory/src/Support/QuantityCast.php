<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<Quantity, Quantity|string|int> */
final class QuantityCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Quantity
    {
        return $value === null ? null : Quantity::fromDecimal((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Quantities are never floats. Pass a Quantity or a decimal string.');
        }

        return ($value instanceof Quantity ? $value : Quantity::fromDecimal((string) $value))->toDecimal();
    }
}
