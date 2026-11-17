<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Record a purchase')];
    }
}
