<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsages extends ManageRecords
{
    protected static string $resource = UsageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New rule')];
    }
}
