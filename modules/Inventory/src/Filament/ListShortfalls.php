<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Resources\Pages\ListRecords;

class ListShortfalls extends ListRecords
{
    protected static string $resource = ShortfallResource::class;
}
