<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New order')];
    }
}
