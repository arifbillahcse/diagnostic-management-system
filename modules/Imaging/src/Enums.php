<?php

declare(strict_types=1);

namespace Modules\Imaging;

final class Enums
{
    /** Study states. Only WriteReport and the order listeners change them. */
    public const STATUSES = [
        'pending' => 'To report',
        'draft' => 'Draft',
        'final' => 'Signed',
        'amending' => 'Being amended',
        'cancelled' => 'Cancelled',
    ];

    /** Allowed moves of the study state machine. */
    public const TRANSITIONS = [
        'pending' => ['draft', 'final', 'cancelled'],
        'draft' => ['draft', 'final'],
        'final' => ['amending'],
        'amending' => ['amending', 'final'],
        'cancelled' => [],
    ];

    /** States in which the report text can be edited. */
    public const EDITABLE = ['pending', 'draft', 'amending'];

    public const SEXES = ['any' => 'Male and female', 'male' => 'Male only', 'female' => 'Female only'];

    public static function status(string $key): string
    {
        return self::STATUSES[$key] ?? $key;
    }

    public static function color(string $key): string
    {
        return ['pending' => 'warning', 'draft' => 'info', 'final' => 'success', 'amending' => 'warning', 'cancelled' => 'gray'][$key] ?? 'gray';
    }
}
