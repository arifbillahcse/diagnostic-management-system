<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Resources\Pages\ListRecords;

class ListMovements extends ListRecords
{
    protected static string $resource = MovementResource::class;
}
