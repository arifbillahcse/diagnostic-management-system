<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans;

use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;
}
