<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Enums;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\Quantity;
use UnitEnum;

/**
 * Stock items with what is on hand. Stock itself changes only through
 * purchases and the other stock entries, never by editing an item.
 */
class ItemResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $modelLabel = 'stock item';

    protected static ?string $slug = 'inventory/stock';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::STOCK_VIEW,
            'create', 'update' => Permissions::ITEMS_MANAGE,
            default => null, // items are made inactive, never deleted
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('category')->options(Enums::CATEGORIES)->required()->native(false)->default('consumable'),
            Select::make('unit')->options(Enums::UNITS)->required()->native(false)->default('piece')
                ->helperText('The unit stock is counted in. A box of 100 tubes counted in tubes is "Piece".'),
            TextInput::make('reorder_level')->label('Warn when stock falls to')->required()->default('0')
                ->rule('regex:/^\d{1,11}(\.\d{1,3})?$/')
                ->formatStateUsing(fn ($state): string => $state instanceof Quantity ? $state->formatted() : (string) ($state ?? '0'))
                ->dehydrateStateUsing(fn ($state): string => (string) $state),
            Toggle::make('tracks_expiry')->label('Has an expiry date (reagents)')->inline(false)
                ->helperText('Each purchase then needs an expiry date, and stock is kept by batch.'),
            Toggle::make('is_active')->label('Active')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->fontFamily('mono'),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Item $record): string => Enums::CATEGORIES[$record->category] ?? $record->category),
                TextColumn::make('quantity')->label('In stock')->alignEnd()
                    ->formatStateUsing(fn (Quantity $state, Item $record): string => $state->formatted().' '.(Enums::UNITS[$record->unit] ?? $record->unit)),
                TextColumn::make('average_cost')->label('Average cost')->alignEnd()
                    ->state(fn (Item $record): string => '৳ '.$record->averageCost()->formatted()),
                TextColumn::make('stock_value')->label('Value')->alignEnd()->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted()),
                TextColumn::make('level')->label('Level')->badge()
                    ->state(fn (Item $record): string => $record->level())
                    ->formatStateUsing(fn (string $state): string => ['out' => 'Out of stock', 'low' => 'Low', 'ok' => 'OK'][$state])
                    ->color(fn (string $state): string => ['out' => 'danger', 'low' => 'warning', 'ok' => 'success'][$state]),
                IconColumn::make('tracks_expiry')->label('Expiry')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->options(Enums::CATEGORIES),
                Filter::make('low')->label('Low or out of stock')
                    ->query(fn (Builder $query) => $query->whereColumn('quantity', '<=', 'reorder_level')),
                SelectFilter::make('is_active')->label('Active')->options(['1' => 'Active', '0' => 'Inactive'])->default('1'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('movements')->label('Movements')->color('gray')
                    ->url(fn (Item $record): string => MovementResource::getUrl('index', ['filters' => ['item_id' => ['value' => $record->id]]])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageItems::route('/')];
    }
}
