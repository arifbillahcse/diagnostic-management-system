<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Invoices;

use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
