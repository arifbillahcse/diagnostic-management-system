<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\FrontDesk\Filament\NewBill;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('newBill')->label('New bill')->icon('heroicon-m-plus')->url(NewBill::getUrl())];
    }
}
