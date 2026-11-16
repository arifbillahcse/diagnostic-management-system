<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Refunds;

use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;
}
