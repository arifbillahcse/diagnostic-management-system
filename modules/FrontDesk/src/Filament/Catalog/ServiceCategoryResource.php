<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Catalog;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class ServiceCategoryResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = ServiceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'categories';

    protected static ?string $slug = 'catalog/categories';

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('sort_order')->numeric()->default(0)->minValue(0)->helperText('Lower numbers appear first, also on the invoice.'),
            TextInput::make('room')->label('Where the patient goes')->maxLength(80)->placeholder('USG (Room 210)')
                ->helperText('Printed on the invoice. Categories with the same text, for example every blood test, are printed once.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('sort_order')->label('Order'),
                TextColumn::make('room')->label('Where the patient goes')->placeholder('-'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceCategories::route('/')];
    }
}
