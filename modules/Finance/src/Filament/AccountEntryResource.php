<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\AccountEntry;
use Modules\Finance\Permissions;
use UnitEnum;

/** Every movement of money, newest first. Read-only. */
class AccountEntryResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = AccountEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Money in and out';

    protected static ?string $modelLabel = 'entry';

    protected static ?string $pluralModelLabel = 'entries';

    protected static ?string $slug = 'finance/entries';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::ACCOUNTS_VIEW : null;
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'payment' => 'Patient payment',
            'refund' => 'Refund',
            'expense' => 'Expense',
            'expense_void' => 'Expense voided',
            'payout' => 'Commission paid',
            'transfer_in', 'transfer_out' => 'Transfer',
            'opening' => 'Opening balance',
            default => ucfirst(str_replace('_', ' ', $source)),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('account'))
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, h:i A', 'Asia/Dhaka'),
                TextColumn::make('account.name')->label('Account'),
                TextColumn::make('source_type')->label('What')->badge()->color('gray')->formatStateUsing(fn (string $state): string => static::sourceLabel($state)),
                TextColumn::make('description')->wrap(),
                TextColumn::make('money_in')->label('In')->alignEnd()
                    ->state(fn (AccountEntry $record): ?string => $record->amount->isPositive() ? '৳ '.$record->amount->formatted() : null),
                TextColumn::make('money_out')->label('Out')->alignEnd()
                    ->state(fn (AccountEntry $record): ?string => $record->amount->isNegative() ? '৳ '.$record->amount->negate()->formatted() : null),
            ])
            ->filters([
                SelectFilter::make('account_id')->label('Account')->options(fn (): array => Account::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAccountEntries::route('/')];
    }
}
