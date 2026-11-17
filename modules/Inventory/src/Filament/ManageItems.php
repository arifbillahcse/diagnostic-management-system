<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New stock item')];
    }
}
