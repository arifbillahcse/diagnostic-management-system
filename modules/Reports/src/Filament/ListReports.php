<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected static ?string $title = 'Reports ready to print';
}
