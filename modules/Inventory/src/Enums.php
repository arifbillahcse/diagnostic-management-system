<?php

declare(strict_types=1);

namespace Modules\Inventory;

/**
 * The fixed lists of the inventory module.
 */
final class Enums
{
    public const CATEGORIES = ['reagent' => 'Reagent', 'consumable' => 'Consumable', 'other' => 'Other'];

    public const UNITS = ['piece' => 'Piece', 'ml' => 'ml', 'litre' => 'Litre', 'gram' => 'Gram', 'box' => 'Box', 'pack' => 'Pack', 'kit' => 'Kit', 'test' => 'Test', 'roll' => 'Roll', 'sheet' => 'Sheet', 'bottle' => 'Bottle'];

    /** Why stock moved. */
    public const REASONS = [
        'purchase' => 'Purchase',
        'purchase_void' => 'Purchase voided',
        'use' => 'Used for a test',
        'unuse' => 'Put back (test cancelled)',
        'issue' => 'Issued',
        'waste' => 'Wasted or broken',
        'expired' => 'Expired, thrown away',
        'count' => 'Stock count',
    ];

    /** The reasons staff choose when taking stock out by hand. */
    public const MANUAL_OUT = ['issue' => 'Issued (used, not for a particular test)', 'waste' => 'Wasted or broken', 'expired' => 'Expired, thrown away'];

    /** Batches expiring within this many days are flagged. */
    public const EXPIRY_WARNING_DAYS = 30;

    public static function reason(string $key): string
    {
        return self::REASONS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
