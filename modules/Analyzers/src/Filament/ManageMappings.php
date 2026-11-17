<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMappings extends ManageRecords
{
    protected static string $resource = MappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a code')];
    }
}
