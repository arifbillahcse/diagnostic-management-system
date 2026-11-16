<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Catalog;

use App\Core\Money\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class ServiceResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Tests and services';

    protected static ?string $slug = 'catalog/services';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static function permissionFor(string $operation): ?string
    {
        // Services are made inactive, not deleted, so old orders keep them.
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('type')->options(ServiceType::options())->required()->native(false)->live()->default(ServiceType::LabTest->value),
                Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => ServiceCategory::orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false),
                TextInput::make('price')
                    ->required()
                    ->prefix('৳')
                    ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
                    ->validationMessages(['regex' => 'Enter an amount such as 500 or 499.50.'])
                    ->formatStateUsing(fn ($state): ?string => $state instanceof Money ? $state->toDecimal() : $state)
                    ->helperText('A new price applies to new orders only. Existing invoices keep the price they were issued at.'),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                Select::make('packageItems')
                    ->label('Tests in this package')
                    ->relationship(
                        'packageItems',
                        'name',
                        fn (Builder $query) => $query->where('type', '!=', ServiceType::Package->value)->where('is_active', true)->orderBy('name'),
                    )
                    ->multiple()
                    ->preload()
                    ->required()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('type') === ServiceType::Package->value),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (ServiceType $state): string => $state->label()),
                TextColumn::make('category.name')->label('Category')->placeholder('-'),
                TextColumn::make('price')->formatStateUsing(fn (Money $state): string => '৳ '.$state->formatted())->alignEnd(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options(ServiceType::options()),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
