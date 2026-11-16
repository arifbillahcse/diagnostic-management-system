<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Actions\ManageExpenses;
use Modules\Finance\Models\Expense;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Permissions;
use UnitEnum;

class ExpenseResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'finance/expenses';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        // Expenses are recorded and voided through actions, never edited.
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::EXPENSES_RECORD : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'account', 'creator']))
            ->columns([
                TextColumn::make('expense_no')->label('No.')->searchable(),
                TextColumn::make('expense_date')->label('Date')->date('d M Y'),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('description')->wrap()->description(fn (Expense $record): ?string => $record->payee),
                TextColumn::make('account.name')->label('Paid from'),
                TextColumn::make('amount')->alignEnd()->weight('bold')->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted()),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Expense $record): string => $record->isVoid() ? 'Void' : 'Recorded')
                    ->color(fn (string $state): string => $state === 'Void' ? 'danger' : 'success')
                    ->description(fn (Expense $record): ?string => $record->void_reason),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Category')->options(fn (): array => ExpenseCategory::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('void')
                    ->color('danger')
                    ->visible(fn (Expense $record): bool => ! $record->isVoid() && Gate::allows(Permissions::EXPENSES_VOID, $record->branch_id))
                    ->modalDescription('The money goes back into the account it was paid from. The expense stays on record, marked void.')
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                    ->action(fn (Action $action, Expense $record, array $data) => Guard::run($action, fn () => app(ManageExpenses::class)->void($record, $data['reason']), 'Expense voided')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenses::route('/')];
    }
}
