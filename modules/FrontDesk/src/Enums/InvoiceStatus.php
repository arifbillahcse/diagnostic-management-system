<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Unpaid',
            self::PartiallyPaid => 'Partly paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
            self::Void => 'Void',
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
