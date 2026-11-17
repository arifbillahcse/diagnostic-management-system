<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

/**
 * The screens this module adds to the panel.
 */
final class Screens
{
    /** @return list<class-string> */
    public static function resources(): array
    {
        return [MessageResource::class, MachineResource::class, MappingResource::class];
    }
}
