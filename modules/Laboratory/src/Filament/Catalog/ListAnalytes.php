<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnalytes extends ListRecords
{
    protected static string $resource = AnalyteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
