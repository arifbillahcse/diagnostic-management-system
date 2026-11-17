<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Modules\FrontDesk\Models\Service;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Usage;
use Modules\Inventory\Permissions;
use Modules\Inventory\Support\Quantity;
use UnitEnum;

/**
 * "Each CBC uses 1 EDTA tube": the rules that take stock out when a test or
 * service is ordered. Changing a rule affects later orders only.
 */
class UsageResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Usage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Stock use per test';

    protected static ?string $modelLabel = 'stock use rule';

    protected static ?string $slug = 'inventory/use-per-test';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::STOCK_VIEW,
            'create', 'update', 'delete' => Permissions::ITEMS_MANAGE,
            default => null,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')->label('Test or service')->required()->searchable()->native(false)
                ->options(fn (): array => Service::where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (Service $s): array => [$s->id => "{$s->code} · {$s->name}"])->all())
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('item_id', $get('item_id')))
                ->validationMessages(['unique' => 'This test already has a rule for that item. Edit it instead.']),
            Select::make('item_id')->label('Uses this item')->required()->searchable()->native(false)
                ->options(fn (): array => Item::where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (Item $i): array => [$i->id => "{$i->code} · {$i->name} ({$i->unit})"])->all()),
            TextInput::make('quantity')->label('How much each time')->required()
                ->rule('regex:/^\d{1,11}(\.\d{1,3})?$/')->rule('not_regex:/^0+(\.0+)?$/')
                ->validationMessages(['not_regex' => 'The amount must be more than zero.'])
                ->formatStateUsing(fn ($state): ?string => $state instanceof Quantity ? $state->formatted() : $state)
                ->helperText('In the item\'s own unit, for example 1 tube, or 2.5 ml of reagent.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['service', 'item']))
            ->columns([
                TextColumn::make('service.name')->label('Test or service')->searchable()->description(fn (Usage $record): ?string => $record->service?->code),
                TextColumn::make('item.name')->label('Uses')->searchable(),
                TextColumn::make('quantity')->label('Each time')->alignEnd()
                    ->formatStateUsing(fn (Quantity $state, Usage $record): string => $state->formatted().' '.$record->item?->unit),
            ])
            ->filters([
                SelectFilter::make('item_id')->label('Item')->options(fn (): array => Item::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsages::route('/')];
    }
}
