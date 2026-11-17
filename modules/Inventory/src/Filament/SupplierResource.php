<?php

declare(strict_types=1);

namespace Modules\Inventory\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Permissions;
use UnitEnum;

class SupplierResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'inventory/suppliers';

    public static function moduleKey(): string
    {
        return 'inventory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::STOCK_VIEW,
            'create', 'update' => Permissions::ITEMS_MANAGE,
            default => null,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('phone')->tel()->maxLength(30),
            TextInput::make('address')->maxLength(255),
            Textarea::make('notes')->rows(2)->maxLength(1000),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('phone')->placeholder('-'),
                TextColumn::make('address')->placeholder('-')->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
