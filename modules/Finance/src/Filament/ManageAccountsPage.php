<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Actions\ManageAccounts;
use Modules\Finance\Models\Account;
use Modules\Finance\Permissions;
use Modules\FrontDesk\Enums\PaymentMethod;

class ManageAccountsPage extends ManageRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        $accounts = fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

        return [
            Action::make('transfer')
                ->label('Transfer money')
                ->color('gray')
                ->visible(fn (): bool => Gate::allows(Permissions::ACCOUNTS_MANAGE))
                ->modalDescription('For example, cash taken from the drawer and deposited in the bank.')
                ->schema([
                    Select::make('from')->label('From')->options($accounts)->required()->native(false),
                    Select::make('to')->label('To')->options($accounts)->required()->native(false)->different('from'),
                    Guard::amount('amount', 'Amount'),
                    TextInput::make('note')->maxLength(200),
                ])
                ->action(fn (Action $action, array $data) => Guard::run($action, fn () => app(ManageAccounts::class)->transfer(
                    Account::findOrFail($data['from']), Account::findOrFail($data['to']), Money::fromDecimal($data['amount']), $data['note'] ?? null,
                ), 'Money transferred')),

            CreateAction::make()
                ->label('Add an account')
                ->using(fn (CreateAction $action, array $data): Account => Guard::run($action, fn () => app(ManageAccounts::class)->create(
                    $data['name'], PaymentMethod::from($data['type']), (bool) ($data['is_default'] ?? false), Money::fromDecimal($data['opening_balance'] ?? '0'),
                ), 'Account added'))
                ->successNotification(null),
        ];
    }
}
