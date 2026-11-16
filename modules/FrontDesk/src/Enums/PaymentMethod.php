<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Card = 'card';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Card => 'Card',
            self::Bank => 'Bank transfer',
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
