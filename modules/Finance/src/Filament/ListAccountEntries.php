<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use Filament\Resources\Pages\ListRecords;

class ListAccountEntries extends ListRecords
{
    protected static string $resource = AccountEntryResource::class;

    protected static ?string $title = 'Money in and out';
}
