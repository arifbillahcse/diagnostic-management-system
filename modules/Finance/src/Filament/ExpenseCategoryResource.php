<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Permissions;
use UnitEnum;

class ExpenseCategoryResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'finance/expense-categories';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::EXPENSES_RECORD : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true)->placeholder('Reagents, Rent, Salaries'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')->searchable(), IconColumn::make('is_active')->label('Active')->boolean()])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()->label('Add a category')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseCategories::route('/')];
    }
}
