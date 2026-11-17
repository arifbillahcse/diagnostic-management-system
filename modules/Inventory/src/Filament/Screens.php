<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [ItemResource::class, PurchaseResource::class, MovementResource::class, SupplierResource::class];
    }
}
