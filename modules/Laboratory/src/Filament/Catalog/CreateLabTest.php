<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use Filament\Resources\Pages\CreateRecord;

class CreateLabTest extends CreateRecord
{
    protected static string $resource = LabTestResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
