<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Enums;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\Quantity;
use UnitEnum;

/**
 * Every change to stock, newest first. Read-only.
 */
class MovementResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Movement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Stock movements';

    protected static ?string $modelLabel = 'stock movement';

    protected static ?string $slug = 'inventory/movements';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return $operation === 'viewAny' ? Permissions::STOCK_VIEW : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['item', 'creator']))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('item.name')->label('Item')->description(fn (Movement $record): ?string => $record->item?->code),
                TextColumn::make('reason')->badge()->color('gray')->formatStateUsing(fn (string $state): string => Enums::reason($state)),
                TextColumn::make('quantity')->alignEnd()
                    ->formatStateUsing(fn (Quantity $state, Movement $record): string => ($state->isPositive() ? '+' : '').$state->formatted().' '.$record->item?->unit)
                    ->color(fn (Quantity $state): string => $state->isNegative() ? 'danger' : 'success'),
                TextColumn::make('value')->alignEnd()->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted()),
                TextColumn::make('batch_no')->label('Batch')->placeholder('-')
                    ->description(fn (Movement $record): ?string => $record->expires_on ? 'Expires '.$record->expires_on->format('d M Y') : null),
                TextColumn::make('note')->placeholder('-')->wrap(),
                TextColumn::make('creator.name')->label('By')->placeholder('-'),
            ])
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([
                BranchFilter::make(),
                SelectFilter::make('item_id')->label('Item')->searchable()
                    ->options(fn (): array => Item::orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('reason')->options(Enums::REASONS),
            ])
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple);
    }

    public static function getPages(): array
    {
        return ['index' => ListMovements::route('/')];
    }
}
