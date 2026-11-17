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

    /** Why stock moved. Later steps add use, issue, waste, expiry, and count. */
    public const REASONS = ['purchase' => 'Purchase', 'purchase_void' => 'Purchase voided'];

    public static function reason(string $key): string
    {
        return self::REASONS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
