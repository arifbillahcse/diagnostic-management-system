<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use Filament\Resources\Pages\ListRecords;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;
}
