<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Actions\AdjustStock;
use Modules\Inventory\Models\Shortfall;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\Quantity;
use UnitEnum;

/**
 * Tests that used stock which was not on hand, usually because a purchase
 * was not entered yet.
 */
class ShortfallResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Shortfall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 35;

    protected static ?string $navigationLabel = 'Used without stock';

    protected static ?string $modelLabel = 'shortfall';

    protected static ?string $slug = 'inventory/used-without-stock';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return $operation === 'viewAny' ? Permissions::STOCK_VIEW : null;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Shortfall::whereNull('resolved_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['item', 'orderItem.order', 'resolver']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('orderItem.service_name')->label('Test')->description(fn (Shortfall $record): ?string => $record->orderItem?->order?->order_no),
                TextColumn::make('item.name')->label('Item'),
                TextColumn::make('quantity')->label('Not in stock')->alignEnd()
                    ->formatStateUsing(fn (Quantity $state, Shortfall $record): string => $state->formatted().' '.$record->item?->unit),
                TextColumn::make('resolved_at')->label('Sorted out')->placeholder('Not yet')
                    ->formatStateUsing(fn ($state, Shortfall $record): string => $state->timezone(app(ClinicContext::class)->timezone())->format('d M Y').' · '.($record->resolve_note ?? '')),
            ])
            ->filters([
                TernaryFilter::make('open')->label('Not yet sorted out')->default(true)
                    ->queries(true: fn (Builder $q) => $q->whereNull('resolved_at'), false: fn (Builder $q) => $q->whereNotNull('resolved_at'), blank: fn (Builder $q) => $q),
            ])
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->recordActions([
                Action::make('resolve')->label('Sorted out')
                    ->visible(fn (Shortfall $record): bool => $record->resolved_at === null && Gate::allows(Permissions::STOCK_ADJUST))
                    ->modalDescription('For example, the purchase was entered late, or the stock count was corrected.')
                    ->schema([TextInput::make('note')->label('How')->required()->maxLength(255)])
                    ->action(fn (Action $action, Shortfall $record, array $data) => Guard::run($action, fn () => app(AdjustStock::class)->resolve($record, $data['note']), 'Marked as sorted out')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShortfalls::route('/')];
    }
}
