<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePhrases extends ManageRecords
{
    protected static string $resource = PhraseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New phrase')];
    }
}
