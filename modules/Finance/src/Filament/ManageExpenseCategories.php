<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use Filament\Resources\Pages\ManageRecords;

class ManageExpenseCategories extends ManageRecords
{
    protected static string $resource = ExpenseCategoryResource::class;
}
