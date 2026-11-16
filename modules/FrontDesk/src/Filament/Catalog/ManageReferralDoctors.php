<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Catalog;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReferralDoctors extends ManageRecords
{
    protected static string $resource = ReferralDoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
