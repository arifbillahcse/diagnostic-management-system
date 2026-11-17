<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New supplier')];
    }
}
