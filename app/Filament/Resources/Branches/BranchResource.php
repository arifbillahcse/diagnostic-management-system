<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches;

use App\Core\Auth\CorePermissions;
use App\Core\Branches\Actions\SaveBranch;
use App\Core\Branches\Branch;
use App\Filament\Concerns\AuthorizesWithPermission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Edits the clinic's branches. Adding more than one branch is unlocked by
 * the multi-branch module, so there is no create button here.
 */
class BranchResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    public static function permission(): string
    {
        return CorePermissions::BRANCHES_MANAGE;
    }

    protected static function allows(string $action, ?Model $record): bool
    {
        // New branches are limited by the plan (SaveBranch); branches are never deleted, only switched off.
        return in_array($action, ['viewAny', 'view', 'create', 'update'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(10)
                ->alphaNum()
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->helperText('Short code printed inside invoice numbers, for example MAIN.'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('address')->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(30),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('phone')->placeholder('-'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Branch $record, array $data, Action $action) => Pages\ManageBranches::guard($action, fn () => app(SaveBranch::class)->update($record, $data))),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBranches::route('/'),
        ];
    }
}
