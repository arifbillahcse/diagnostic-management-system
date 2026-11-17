<?php

declare(strict_types=1);

namespace App\Filament\Resources\Signatures\Pages;

use App\Filament\Resources\Signatures\SignatureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSignatures extends ManageRecords
{
    protected static string $resource = SignatureResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a signature')];
    }
}
