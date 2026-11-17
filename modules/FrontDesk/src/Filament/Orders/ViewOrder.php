<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use App\Core\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Filament\Concerns\RunsFrontDeskActions;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\OrderReports;

/**
 * One order with its invoice: what was ordered, what was charged, what was
 * paid, and every action that can change it.
 *
 * @property Order $record
 */
class ViewOrder extends Page
{
    use InteractsWithRecord;
    use RunsFrontDeskActions;

    protected static string $resource = OrderResource::class;

    protected string $view = 'frontdesk::order';

    public string $paymentKey = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->paymentKey = (string) Str::uuid();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Order '.$this->record->order_no;
    }

    public function getInvoiceProperty(): Invoice
    {
        return $this->record->invoice()->with(['lines', 'payments.receiver', 'refunds.requester', 'refunds.decider'])->firstOrFail();
    }

    /** @return list<array{label: string, status: string, color: string, url: ?string}> */
    public function getReportsProperty(): array
    {
        return app(OrderReports::class)->for($this->record);
    }

    private function open(): bool
    {
        return ! in_array($this->record->status, [OrderStatus::Cancelled, OrderStatus::Completed], true);
    }

    private function can(string $permission): bool
    {
        return Gate::allows($permission, $this->record->branch_id);
    }

    private function amountField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('৳')->required()
            ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter an amount such as 500 or 499.50.']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')
                ->label('Take payment')
                ->visible(fn (): bool => $this->can(Permissions::PAYMENTS_RECORD) && $this->invoice->due_amount->isPositive() && ! $this->invoice->isVoid())
                ->fillForm(fn (): array => ['amount' => $this->invoice->due_amount->toDecimal(), 'method' => PaymentMethod::Cash->value])
                ->schema([
                    $this->amountField('amount', 'Amount received'),
                    Select::make('method')->options(PaymentMethod::options())->required()->native(false),
                    TextInput::make('reference')->maxLength(255)->placeholder('Transaction ID, if any'),
                ])
                ->action(function (array $data): void {
                    $paid = $this->attempt(fn () => app(RecordPayment::class)->handle(
                        $this->invoice,
                        Money::fromDecimal($data['amount']),
                        PaymentMethod::from($data['method']),
                        $data['reference'] ?? null,
                        $this->paymentKey,
                    ), 'Payment recorded', 'Payment not recorded');

                    if ($paid) {
                        $this->paymentKey = (string) Str::uuid();
                    }
                }),

            Action::make('printInvoice')
                ->label('Print invoice')
                ->color('gray')
                ->url(fn (): string => route('frontdesk.print.invoice', $this->invoice->public_id), shouldOpenInNewTab: true),

            ActionGroup::make([
                Action::make('addTest')
                    ->label('Add a test')
                    ->visible(fn (): bool => $this->open() && $this->can(Permissions::ORDERS_ADJUST))
                    ->schema([
                        Select::make('service_id')->label('Test or service')->required()->searchable()
                            ->options(fn (): array => Service::where('is_active', true)->orderBy('name')->get()
                                ->mapWithKeys(fn (Service $s): array => [$s->id => "{$s->name}, ৳ {$s->price->formatted()}"])->all()),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(AdjustOrder::class)->addItem($this->record, (int) $data['service_id']),
                        'Test added',
                    )),

                Action::make('cancelTest')
                    ->label('Cancel a test')
                    ->visible(fn (): bool => $this->open() && $this->can(Permissions::ORDERS_ADJUST))
                    ->schema([
                        Select::make('item_id')->label('Test')->required()->native(false)
                            ->options(fn (): array => $this->record->items()
                                ->where('status', ItemStatus::Active->value)->whereNull('package_item_of')
                                ->pluck('service_name', 'id')->all()),
                        Textarea::make('reason')->required()->rows(2)->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(AdjustOrder::class)->cancelItem($this->record->items()->findOrFail($data['item_id']), $data['reason']),
                        'Test cancelled',
                    )),

                Action::make('refund')
                    ->label('Request a refund')
                    ->visible(fn (): bool => $this->can(Permissions::REFUNDS_REQUEST) && $this->invoice->netPaid()->isPositive())
                    ->fillForm(fn (): array => [
                        'amount' => $this->invoice->credit()->isPositive() ? $this->invoice->credit()->toDecimal() : null,
                        'method' => PaymentMethod::Cash->value,
                    ])
                    ->schema([
                        $this->amountField('amount', 'Amount to refund'),
                        Select::make('method')->options(PaymentMethod::options())->required()->native(false),
                        Textarea::make('reason')->required()->rows(2)->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(RequestRefund::class)->handle($this->invoice, Money::fromDecimal($data['amount']), PaymentMethod::from($data['method']), $data['reason']),
                        'Refund requested',
                    )),

                Action::make('cancelOrder')
                    ->label('Cancel the order')
                    ->color('danger')
                    ->visible(fn (): bool => $this->open() && $this->can(Permissions::ORDERS_CANCEL) && $this->invoice->netPaid()->isZero())
                    ->modalDescription('The order is cancelled and its invoice is voided. This cannot be undone.')
                    ->schema([
                        Textarea::make('reason')->required()->rows(2)->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(AdjustOrder::class)->cancelOrder($this->record, $data['reason']),
                        'Order cancelled',
                    )),
            ])->label('More')->button()->color('gray'),
        ];
    }
}
