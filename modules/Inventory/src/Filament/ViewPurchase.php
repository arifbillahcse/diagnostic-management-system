<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\Inventory\Actions\ManagePurchases;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Permissions;

/**
 * @property Purchase $record
 */
class ViewPurchase extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'inventory::purchase';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return 'Purchase '.$this->record->purchase_no;
    }

    protected function getHeaderActions(): array
    {
        $financeOn = fn (): bool => app(ModuleState::class)->isEnabled('finance');
        $open = fn (): bool => ! $this->record->isVoid() && ! $this->record->isPaid();

        return [
            Action::make('pay')
                ->label('Mark paid')
                ->visible(fn (): bool => $open() && Gate::allows(Permissions::PURCHASES_PAY, $this->record->branch_id))
                ->modalDescription(fn (): string => $financeOn()
                    ? "৳ {$this->record->total->formatted()} is recorded as an expense (Purchases) from the account you choose."
                    : 'The finance module is off, so only the payment date is kept.')
                ->fillForm(fn (): array => ['date' => now(app(ClinicContext::class)->timezone())->toDateString()])
                ->schema(fn (): array => array_values(array_filter([
                    DatePicker::make('date')->label('Paid on')->required(),
                    $financeOn() && $this->record->total->isPositive()
                        ? Select::make('account_id')->label('Paid from')->required()->native(false)
                            ->options(fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        : null,
                    TextInput::make('note')->label('Note')->maxLength(255),
                ])))
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(ManagePurchases::class)->pay($this->record, (string) $data['date'], isset($data['account_id']) ? (int) $data['account_id'] : null, $data['note'] ?? null), 'Purchase marked paid');
                    $this->record->refresh();
                }),
            Action::make('void')
                ->label('Void')
                ->color('danger')
                ->visible(fn (): bool => $open() && Gate::allows(Permissions::PURCHASES_VOID, $this->record->branch_id))
                ->modalDescription('The items on this purchase are taken out of stock again. Use this for a purchase entered by mistake.')
                ->schema([Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(255)])
                ->action(function (Action $action, array $data): void {
                    Guard::run($action, fn () => app(ManagePurchases::class)->void($this->record, $data['reason']), 'Purchase voided');
                    $this->record->refresh();
                }),
        ];
    }
}
