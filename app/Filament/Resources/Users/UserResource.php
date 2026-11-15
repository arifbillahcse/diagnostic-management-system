<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Core\Auth\CorePermissions;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Filament\Concerns\AuthorizesWithPermission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function permission(): string
    {
        return CorePermissions::USERS_MANAGE;
    }

    protected static function allows(string $action, ?Model $record): bool
    {
        // Staff are deactivated, never deleted, so their history stays readable.
        return ! in_array($action, ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                    TextInput::make('phone')->tel()->maxLength(30),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave empty to keep the current password.' : null),
                    Select::make('default_branch_id')
                        ->label('Default branch')
                        ->options(fn (): array => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false)
                        ->helperText('An inactive user cannot sign in.'),
                ]),
            Section::make('Roles')
                ->description('Roles decide what this person can see and do.')
                ->schema([
                    CheckboxList::make('role_ids')
                        ->hiddenLabel()
                        ->options(fn (): array => Role::orderBy('name')->pluck('name', 'id')->all())
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
