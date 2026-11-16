<?php

declare(strict_types=1);

namespace App\Core\Patients;

enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $s): array => [$s->value, $s->label()], self::cases()), 1, 0);
    }
}
