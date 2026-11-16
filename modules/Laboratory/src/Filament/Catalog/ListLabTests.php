<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabTests extends ListRecords
{
    protected static string $resource = LabTestResource::class;

    public function getSubheading(): ?string
    {
        $missing = LabTestResource::notSetUp()->orderBy('name')->limit(8)->pluck('name');

        return $missing->isEmpty()
            ? null
            : 'Still need a set-up, so no results can be entered for them yet: '.$missing->implode(', ').'.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Set up a test')];
    }
}
