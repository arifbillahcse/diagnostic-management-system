<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Enums;

enum ServiceType: string
{
    case LabTest = 'lab_test';
    case Imaging = 'imaging';
    case Package = 'package';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LabTest => 'Lab test',
            self::Imaging => 'Imaging',
            self::Package => 'Package',
            self::Other => 'Other',
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
