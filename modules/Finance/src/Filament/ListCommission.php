<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use Filament\Resources\Pages\ListRecords;

class ListCommission extends ListRecords
{
    protected static string $resource = CommissionResource::class;

    protected static ?string $title = 'Referral commission';

    public function getSubheading(): ?string
    {
        return 'A doctor earns commission on a test when its invoice is fully paid, on what the patient paid for that test. It is taken back if the test is cancelled.';
    }
}
