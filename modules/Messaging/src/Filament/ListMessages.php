<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

use Filament\Resources\Pages\ListRecords;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;
}
