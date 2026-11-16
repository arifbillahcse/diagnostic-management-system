<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Laboratory\Models\SampleType;
use Modules\Laboratory\Permissions;
use UnitEnum;

class SampleTypeResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = SampleType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEyeDropper;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'lab-setup/sample-types';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true)->placeholder('Blood (EDTA)'),
            TextInput::make('container')->maxLength(255)->placeholder('Lavender top tube'),
            TextInput::make('label_copies')->label('Labels to print')->numeric()->minValue(1)->maxValue(10)->default(1)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('container')->placeholder('-'),
                TextColumn::make('label_copies')->label('Labels'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSampleTypes::route('/')];
    }
}
