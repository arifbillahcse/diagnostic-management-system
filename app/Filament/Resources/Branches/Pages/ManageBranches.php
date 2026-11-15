<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBranches extends ManageRecords
{
    protected static string $resource = BranchResource::class;
}
