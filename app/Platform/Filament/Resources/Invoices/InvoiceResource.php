<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Invoices;

use App\Core\Tenancy\Clinic;
use App\Platform\Billing\Billing;
use App\Platform\Billing\SubscriptionInvoice;
use App\Platform\Billing\SubscriptionPayment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

/** Every subscription invoice to every clinic. */
class InvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyBangladeshi;

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $slug = 'invoices';

    public static function getNavigationBadge(): ?string
    {
        $unpaid = SubscriptionInvoice::where('status', 'issued')->count();

        return $unpaid > 0 ? (string) $unpaid : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('clinic'))
            ->columns([
                TextColumn::make('invoice_no')->label('No.')->searchable(),
                TextColumn::make('clinic.name')->label('Clinic')->searchable(),
                TextColumn::make('plan_name')->label('Plan')->description(fn (SubscriptionInvoice $r): string => ucfirst($r->billing_period)),
                TextColumn::make('period_start')->label('Period')
                    ->formatStateUsing(fn (SubscriptionInvoice $r): string => $r->period_start->format('d M Y').' to '.$r->period_end->format('d M Y')),
                TextColumn::make('amount')->alignEnd()->formatStateUsing(fn ($state): string => '৳ '.$state->formatted()),
                TextColumn::make('due_on')->label('Due')->date('d M Y')->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => SubscriptionInvoice::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => SubscriptionInvoice::color($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(SubscriptionInvoice::STATUSES),
                SelectFilter::make('clinic_id')->label('Clinic')->options(fn (): array => Clinic::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('pay')->label('Record payment')
                    ->visible(fn (SubscriptionInvoice $r): bool => $r->status === 'issued')
                    ->modalDescription(fn (SubscriptionInvoice $r): string => "৳ {$r->amount->formatted()} in full. The clinic is then paid until {$r->period_end->format('d M Y')}.")
                    ->fillForm(fn (): array => ['received_on' => now('Asia/Dhaka')->toDateString(), 'method' => 'bkash'])
                    ->schema([
                        Select::make('method')->label('Received by')->options(SubscriptionPayment::METHODS)->required()->native(false),
                        TextInput::make('reference')->label('Transaction ID or reference')->maxLength(100),
                        DatePicker::make('received_on')->label('Received on')->required()->native(false)->maxDate(now('Asia/Dhaka')),
                    ])
                    ->action(fn (Action $action, SubscriptionInvoice $record, array $data) => static::run($action, fn () => app(Billing::class)
                        ->recordPayment($record, (string) $data['method'], (string) $data['received_on'], $data['reference'] ?? null), 'Payment recorded')),
                Action::make('print')->label('Print')->color('gray')
                    ->url(fn (SubscriptionInvoice $r): string => route('platform.invoice.print', $r->public_id), shouldOpenInNewTab: true),
                Action::make('void')->label('Void')->color('danger')
                    ->visible(fn (SubscriptionInvoice $r): bool => $r->status === 'issued')
                    ->modalDescription('For a wrong invoice. Issue a correct one afterwards.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Action $action, SubscriptionInvoice $record, array $data) => static::run($action, fn () => app(Billing::class)->void($record, (string) $data['reason']), 'Invoice voided')),
            ]);
    }

    public static function run(?Action $action, \Closure $work, string $done): mixed
    {
        try {
            $result = $work();
            Notification::make()->success()->title($done)->send();

            return $result;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            $action?->halt();
        }

        return null;
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
