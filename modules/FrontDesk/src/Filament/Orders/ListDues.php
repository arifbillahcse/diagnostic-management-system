<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use Filament\Resources\Pages\ListRecords;

class ListDues extends ListRecords
{
    protected static string $resource = DueResource::class;

    protected static ?string $title = 'Dues';
}
