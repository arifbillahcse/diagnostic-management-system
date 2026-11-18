<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Clinics;

use Filament\Resources\Pages\ListRecords;

class ListClinics extends ListRecords
{
    protected static string $resource = ClinicResource::class;
}
