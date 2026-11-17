<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\Quantity;
use UnitEnum;

class PurchaseResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'inventory/purchases';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::STOCK_VIEW,
            'create' => Permissions::PURCHASES_RECORD,
            default => null, // a purchase is never edited or deleted
        };
    }

    public static function form(Schema $schema): Schema
    {
        $amount = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $quantity = 'regex:/^\d{1,11}(\.\d{1,3})?$/';

        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->native(false)
                    ->options(fn (): array => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                DatePicker::make('purchase_date')->label('Date')->required()
                    ->default(fn (): string => now(app(ClinicContext::class)->timezone())->toDateString())
                    ->maxDate(fn (): string => now(app(ClinicContext::class)->timezone())->toDateString()),
                TextInput::make('supplier_bill_no')->label("Supplier's bill no.")->maxLength(60),
            ]),
            Repeater::make('lines')
                ->label('Items received')
                ->columnSpanFull()
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('Add another item')
                ->columns(12)
                ->live()
                ->schema([
                    Select::make('item_id')->label('Item')->required()->searchable()->native(false)->live()->columnSpan(4)
                        ->options(fn (): array => Item::where('is_active', true)->orderBy('name')->get()
                            ->mapWithKeys(fn (Item $i): array => [$i->id => "{$i->code} · {$i->name} ({$i->unit})"])->all()),
                    TextInput::make('quantity')->label('Quantity')->required()->rule($quantity)->live(onBlur: true)->columnSpan(2),
                    TextInput::make('unit_cost')->label('Unit cost')->prefix('৳')->required()->rule($amount)->live(onBlur: true)->columnSpan(2),
                    TextInput::make('batch_no')->label('Batch')->maxLength(60)->columnSpan(2),
                    DatePicker::make('expires_on')->label('Expires')->columnSpan(2)
                        ->required(fn (Get $get): bool => (bool) Item::find($get('item_id'))?->tracks_expiry),
                ]),
            Text::make(fn (Get $get): string => 'Total: ৳ '.static::total($get('lines') ?? [])->formatted())->weight('bold'),
            Textarea::make('note')->rows(2)->maxLength(1000)->columnSpanFull(),
        ]);
    }

    /** @param array<array-key, array<string, mixed>> $lines */
    public static function total(array $lines): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            try {
                $total = $total->plus(Quantity::fromDecimal((string) ($line['quantity'] ?? ''))->costAt(Money::fromDecimal((string) ($line['unit_cost'] ?? ''))));
            } catch (InvalidArgumentException) {
                // an unfinished line counts as nothing yet
            }
        }

        return $total;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier'))
            ->columns([
                TextColumn::make('purchase_no')->label('Purchase')->searchable(),
                TextColumn::make('purchase_date')->label('Date')->date('d M Y'),
                TextColumn::make('supplier.name')->label('Supplier')->description(fn (Purchase $record): ?string => $record->supplier_bill_no ? "Bill {$record->supplier_bill_no}" : null),
                TextColumn::make('total')->alignEnd()->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted()),
                TextColumn::make('state')->label('Payment')->badge()
                    ->state(fn (Purchase $record): string => $record->state())
                    ->formatStateUsing(fn (string $state): string => ['void' => 'Void', 'paid' => 'Paid', 'unpaid' => 'Unpaid'][$state])
                    ->color(fn (string $state): string => ['void' => 'gray', 'paid' => 'success', 'unpaid' => 'danger'][$state]),
            ])
            ->filters([
                SelectFilter::make('supplier_id')->label('Supplier')->options(fn (): array => Supplier::orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('payment')->label('Payment')
                    ->options(['unpaid' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unpaid' => $query->whereNull('paid_at')->whereNull('voided_at'),
                        'paid' => $query->whereNotNull('paid_at'),
                        'void' => $query->whereNotNull('voided_at'),
                        default => $query,
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
        ];
    }
}
