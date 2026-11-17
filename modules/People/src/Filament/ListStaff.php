<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Modules\People\Actions\ManageStaff;
use Modules\People\Permissions;
use Modules\People\Support\Branches;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')->label('Add staff')
                ->visible(fn (): bool => Gate::allows(Permissions::STAFF_MANAGE))
                ->fillForm(fn (): array => ['branch_id' => Branches::default(Permissions::STAFF_MANAGE), 'monthly_salary' => '0'])
                ->schema(fn (): array => [
                    ...StaffResource::detailFields(),
                    StaffResource::salaryField()->visible(fn (): bool => StaffResource::canSeeSalaries())
                        ->helperText('Later changes go through "Change salary", so the history is kept.'),
                ])
                ->action(fn (Action $action, array $data) => Guard::run($action, fn () => app(ManageStaff::class)->add($data), 'Staff member added')),
        ];
    }
}
