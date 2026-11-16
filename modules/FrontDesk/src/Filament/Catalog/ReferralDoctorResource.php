<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament\Catalog;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\FrontDesk\Filament\Concerns\FrontDeskScreen;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Permissions;
use UnitEnum;

class ReferralDoctorResource extends Resource
{
    use FrontDeskScreen;

    protected static ?string $model = ReferralDoctor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'catalog/referral-doctors';

    protected static function permissionFor(string $operation): ?string
    {
        // Doctors are made inactive, not deleted, so old orders keep their name.
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('degree')->maxLength(255)->placeholder('MBBS, FCPS'),
            TextInput::make('phone')->tel()->maxLength(30),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('degree')->placeholder('-'),
                TextColumn::make('phone')->placeholder('-'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReferralDoctors::route('/')];
    }
}
