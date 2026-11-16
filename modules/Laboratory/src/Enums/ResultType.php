<?php

declare(strict_types=1);

namespace Modules\Laboratory\Enums;

enum ResultType: string
{
    case Numeric = 'numeric';
    case Option = 'option';
    case Text = 'text';
    case LongText = 'long_text';

    public function label(): string
    {
        return match ($this) {
            self::Numeric => 'Number',
            self::Option => 'Choice from a list',
            self::Text => 'Short text',
            self::LongText => 'Long text',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c): array => [$c->value, $c->label()], self::cases()), 1, 0);
    }
}
