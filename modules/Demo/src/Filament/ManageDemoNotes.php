<?php

declare(strict_types=1);

namespace Modules\Demo\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDemoNotes extends ManageRecords
{
    protected static string $resource = DemoNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
