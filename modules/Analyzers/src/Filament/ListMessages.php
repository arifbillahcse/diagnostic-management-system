<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use Filament\Resources\Pages\ListRecords;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;
}
