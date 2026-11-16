<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Resources\Pages\CreateRecord;

class CreateAnalyte extends CreateRecord
{
    protected static string $resource = AnalyteResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
