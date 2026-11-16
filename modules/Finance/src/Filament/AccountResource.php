<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Actions\ManageAccounts;
use Modules\Finance\Models\Account;
use Modules\Finance\Permissions;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Enums\PaymentMethod;
use UnitEnum;

class AccountResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'finance/accounts';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::ACCOUNTS_VIEW,
            'create', 'update' => Permissions::ACCOUNTS_MANAGE,
            default => null, // an account with history is made inactive, never deleted
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true)->placeholder('City Bank current account'),
            Select::make('type')->label('Kind')->options(PaymentMethod::options())->required()->native(false)->disabledOn('edit')
                ->helperText('Money received by this payment method can go into this account.'),
            Toggle::make('is_default')->label('Payments by this method go here')
                ->helperText('One account per payment method receives its payments.'),
            Guard::amount('opening_balance', 'Money in it now')->default('0')->visibleOn('create'),
            Toggle::make('is_active')->label('Active')->default(true)->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $balances = null;

        return $table
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('type')->label('Kind')->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                IconColumn::make('is_default')->label('Receives payments')->boolean(),
                TextColumn::make('balance')->alignEnd()->weight('bold')
                    ->state(function (Account $record) use (&$balances): string {
                        $balances ??= app(Ledger::class)->balances();

                        return '৳ '.($balances[$record->id] ?? Money::zero())->formatted();
                    }),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->recordActions([
                Action::make('ledger')->label('Entries')->color('gray')
                    ->url(fn (Account $record): string => AccountEntryResource::getUrl('index', ['filters' => ['account_id' => ['value' => $record->id]]])),
                EditAction::make()
                    ->visible(fn (): bool => Gate::allows(Permissions::ACCOUNTS_MANAGE))
                    ->using(fn (EditAction $action, Account $record, array $data): Account => Guard::run(
                        $action,
                        fn () => app(ManageAccounts::class)->update($record, $data['name'], (bool) $data['is_default'], (bool) $data['is_active']),
                        'Account saved',
                    ))
                    ->successNotification(null),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccountsPage::route('/')];
    }
}
