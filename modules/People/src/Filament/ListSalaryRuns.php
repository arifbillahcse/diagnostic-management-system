<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Branches\Branch;
use App\Core\Tenancy\ClinicContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Modules\People\Actions\RunPayroll;
use Modules\People\Permissions;
use Modules\People\Support\Branches;

class ListSalaryRuns extends ListRecords
{
    protected static string $resource = SalaryRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('New salary run')
                ->visible(fn (): bool => Gate::allows(Permissions::PAYROLL_PREPARE))
                ->modalDescription('Made from each person\'s salary and the month\'s attendance. Nothing is deducted until you type it.')
                ->fillForm(fn (): array => [
                    'month' => now(app(ClinicContext::class)->timezone())->subMonthNoOverflow()->format('Y-m'),
                    'branch_id' => Branches::default(Permissions::PAYROLL_PREPARE),
                ])
                ->schema([
                    TextInput::make('month')->type('month')->required()->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/'),
                    Select::make('branch_id')->label('Branch')->required()->native(false)
                        ->options(fn (): array => Branches::options(Permissions::PAYROLL_PREPARE)),
                ])
                ->action(function (Action $action, array $data): void {
                    $run = Guard::run($action, fn () => app(RunPayroll::class)->start(Branch::findOrFail($data['branch_id']), (string) $data['month']), 'Salary run started');

                    if ($run !== null) {
                        $this->redirect(SalaryRunResource::getUrl('view', ['record' => $run]));
                    }
                }),
        ];
    }
}
