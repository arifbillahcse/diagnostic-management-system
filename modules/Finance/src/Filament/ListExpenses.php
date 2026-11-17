<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Branches\CurrentBranch;
use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ExpenseCategory;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label('Record an expense')
                ->fillForm(fn (): array => ['expense_date' => now(app(ClinicContext::class)->timezone())->toDateString()])
                ->schema([
                    Select::make('category_id')->label('Category')->required()->native(false)
                        ->options(fn (): array => ExpenseCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('account_id')->label('Paid from')->required()->native(false)
                        ->options(fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                    Guard::amount('amount', 'Amount'),
                    DatePicker::make('expense_date')->label('Date')->required()->maxDate(fn () => now(app(ClinicContext::class)->timezone())->toDateString()),
                    TextInput::make('description')->label('What for')->required()->maxLength(255),
                    TextInput::make('payee')->label('Paid to')->maxLength(255),
                ])
                ->action(fn (Action $action, array $data) => Guard::run($action, fn () => app(ManageExpenses::class)->record(
                    app(CurrentBranch::class)->get(),
                    ExpenseCategory::findOrFail($data['category_id']),
                    Account::findOrFail($data['account_id']),
                    Money::fromDecimal($data['amount']),
                    (string) $data['expense_date'],
                    $data['description'],
                    $data['payee'] ?? null,
                ), 'Expense recorded')),
        ];
    }
}
