<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Catalog;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageServiceCategories extends ManageRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
