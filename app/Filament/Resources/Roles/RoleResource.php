<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Core\Auth\Actions\SaveRole;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\Role;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use App\Filament\Concerns\AuthorizesWithPermission;
use BackedEnum;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RoleResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function permission(): string
    {
        return CorePermissions::ROLES_MANAGE;
    }

    protected static function allows(string $action, ?Model $record): bool
    {
        if (in_array($action, ['deleteAny', 'forceDelete', 'forceDeleteAny'], true)) {
            return false;
        }

        // The built-in owner role always has everything and cannot be changed.
        return ! ($record instanceof Role && $record->is_system && in_array($action, ['update', 'delete'], true));
    }

    /**
     * Permissions the clinic can grant right now: core, plus enabled modules.
     *
     * @return array<string, array{module: string, description: string}>
     */
    public static function grantablePermissions(): array
    {
        $state = app(ModuleState::class);

        return array_filter(
            app(PermissionRegistry::class)->definitions(),
            fn (array $definition): bool => $definition['module'] === 'core' || $state->isEnabled($definition['module']),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required()->maxLength(255),
            ]),
            Section::make('Permissions')
                ->description('Only permissions of modules that are switched on are listed.')
                ->schema([
                    CheckboxList::make('permission_keys')
                        ->hiddenLabel()
                        ->options(function (): array {
                            $modules = app(ModuleRegistry::class);

                            return collect(static::grantablePermissions())
                                ->map(fn (array $d): string => ($d['module'] === 'core' ? 'Core' : ($modules->get($d['module'])?->name ?? $d['module'])).': '.$d['description'])
                                ->all();
                        })
                        ->bulkToggleable()
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['permissions', 'users']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->formatStateUsing(fn (Role $record, $state): string => $record->isOwner() ? 'All' : (string) $state),
                TextColumn::make('users_count')->label('Users'),
                TextColumn::make('is_system')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Built in' : 'Editable')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(function (Role $record): bool {
                    try {
                        app(SaveRole::class)->delete($record);

                        return true;
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title('Not deleted')->body($e->getMessage())->send();

                        return false;
                    }
                }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
