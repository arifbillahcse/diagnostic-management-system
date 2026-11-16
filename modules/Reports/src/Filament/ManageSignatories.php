<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSignatories extends ManageRecords
{
    protected static string $resource = SignatoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a signatory')];
    }
}
