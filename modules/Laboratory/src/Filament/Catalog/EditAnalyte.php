<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Resources\Pages\EditRecord;

class EditAnalyte extends EditRecord
{
    protected static string $resource = AnalyteResource::class;

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
