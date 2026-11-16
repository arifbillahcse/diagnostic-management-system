<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Enums;

enum LineKind: string
{
    case Charge = 'charge';
    case Discount = 'discount';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Discount => 'Discount',
            self::Adjustment => 'Adjustment',
        };
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
