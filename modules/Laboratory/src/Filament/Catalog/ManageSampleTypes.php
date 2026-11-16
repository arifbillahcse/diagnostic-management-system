<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSampleTypes extends ManageRecords
{
    protected static string $resource = SampleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
