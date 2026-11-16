<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Orders;

use App\Core\Auth\CorePermissions;
use App\Core\Branches\CurrentBranch;
use App\Core\Money\Money;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Patients\Patient;
use App\Filament\Resources\Patients\PatientResource;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class OrderResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'order_no';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::ORDERS_VIEW,
            'create' => Permissions::ORDERS_CREATE,
            default => null, // changes go through the actions on the order screen
        };
    }

    public static function invoiceStatusColor(?InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Paid => 'success',
            InvoiceStatus::PartiallyPaid => 'warning',
            InvoiceStatus::Issued => 'danger',
            InvoiceStatus::Refunded => 'info',
            default => 'gray',
        };
    }

    public static function patientLabel(Patient $patient): string
    {
        return "{$patient->name} ({$patient->patient_code})".($patient->phone ? ", {$patient->phone}" : '');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Patient')->columns(2)->columnSpanFull()->schema([
                Select::make('patient_id')
                    ->label('Patient')
                    ->required()
                    ->searchable()
                    ->searchPrompt('Type a code, the start of a name, or a phone number')
                    ->getSearchResultsUsing(fn (string $search): array => Patient::search($search)->limit(20)->get()
                        ->mapWithKeys(fn (Patient $p): array => [$p->id => static::patientLabel($p)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Patient::find($value)) ? static::patientLabel($p) : null)
                    ->createOptionForm(Gate::allows(CorePermissions::PATIENTS_CREATE) ? [
                        Section::make()->columns(3)->schema(PatientResource::fields()),
                    ] : null)
                    ->createOptionUsing(fn (array $data): int => app(SavePatient::class)->register($data, app(CurrentBranch::class)->get())->id),
                Select::make('referral_doctor_id')
                    ->label('Referred by')
                    ->searchable()
                    ->options(fn (): array => ReferralDoctor::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            ]),
            Section::make('Tests and services')->columnSpanFull()->schema([
                Select::make('service_ids')
                    ->hiddenLabel()
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->live()
                    ->options(fn (): array => Service::where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (Service $s): array => [$s->id => "{$s->name} ({$s->code}), ৳ {$s->price->formatted()}"])->all()),
                Text::make(fn (Get $get): string => 'Subtotal: ৳ '.static::subtotal($get('service_ids') ?? [])->formatted())->weight('bold'),
            ]),
            Section::make('Discount')->columns(3)->columnSpanFull()->collapsed()->schema([
                Select::make('discount_type')
                    ->label('Type')
                    ->options(['percent' => 'Percent', 'amount' => 'Fixed amount'])
                    ->placeholder('No discount')
                    ->native(false)
                    ->live(),
                TextInput::make('discount_value')
                    ->label('Value')
                    ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
                    ->required(fn (Get $get): bool => filled($get('discount_type')))
                    ->visible(fn (Get $get): bool => filled($get('discount_type'))),
                TextInput::make('discount_reason')
                    ->label('Reason')
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => filled($get('discount_type')))
                    ->visible(fn (Get $get): bool => filled($get('discount_type'))),
            ]),
            Section::make('Payment now')
                ->description('Leave the amount empty to collect later. The rest stays as a due.')
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (): bool => Gate::allows(Permissions::PAYMENTS_RECORD))
                ->schema([
                    TextInput::make('pay_amount')->label('Amount received')->prefix('৳')->rule('regex:/^\d{1,10}(\.\d{1,2})?$/'),
                    Select::make('pay_method')->label('Method')->options(PaymentMethod::options())->default(PaymentMethod::Cash->value)->native(false),
                    TextInput::make('pay_reference')->label('Reference')->maxLength(255)->placeholder('Transaction ID, if any'),
                ]),
            DateTimePicker::make('delivery_at')
                ->label('Report ready at')
                ->seconds(false)
                ->helperText('Leave empty to use the clinic\'s usual time (Front desk settings).'),
            Textarea::make('notes')->label('Remarks')->rows(2)->maxLength(1000)->columnSpanFull(),
        ]);
    }

    /** @param array<int|string> $serviceIds */
    public static function subtotal(array $serviceIds): Money
    {
        return $serviceIds === []
            ? Money::zero()
            : Money::sum(...Service::whereIn('id', $serviceIds)->get()->map->price->all());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'invoice']))
            ->columns([
                TextColumn::make('order_no')->label('Order')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        // Index-friendly: order numbers from their start, or the patient search.
                        $q->where('order_no', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')
                            ->orWhereIn('patient_id', Patient::search($search)->select('id'));
                    })),
                TextColumn::make('ordered_at')->label('Date')->dateTime('d M Y, h:i A', 'Asia/Dhaka'),
                TextColumn::make('patient.name')->label('Patient')->description(fn (Order $record): ?string => $record->patient?->patient_code),
                TextColumn::make('invoice.total')->label('Total')->alignEnd()->formatStateUsing(fn (?Money $state): string => '৳ '.($state?->formatted() ?? '0.00')),
                TextColumn::make('invoice.due_amount')->label('Due')->alignEnd()->formatStateUsing(fn (?Money $state): string => '৳ '.($state?->formatted() ?? '0.00')),
                TextColumn::make('invoice.status')->label('Payment')->badge()
                    ->formatStateUsing(fn (?InvoiceStatus $state): string => $state?->label() ?? '-')
                    ->color(fn (?InvoiceStatus $state): string => static::invoiceStatusColor($state)),
                TextColumn::make('status')->badge()->color('gray')->formatStateUsing(fn (OrderStatus $state): string => $state->label()),
            ])
            ->searchPlaceholder('Order number or patient')
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::options()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
