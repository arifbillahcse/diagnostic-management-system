<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

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
use Illuminate\Validation\Rules\Unique;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Mapping;
use Modules\Analyzers\Permissions;
use Modules\Laboratory\Models\Analyte;
use UnitEnum;

/** "This machine calls haemoglobin HGB": how each machine's test codes match our analytes. */
class MappingResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Mapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Lab machines';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Test codes';

    protected static ?string $modelLabel = 'test code';

    protected static ?string $slug = 'lab-machines/codes';

    public static function moduleKey(): string
    {
        return 'analyzers';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update', 'delete'], true) ? Permissions::MACHINES_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('machine_id')->label('Machine')->required()->native(false)->options(fn (): array => Machine::orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('machine_code')->label('Code the machine sends')->required()->maxLength(50)->placeholder('HGB')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('machine_id', $get('machine_id')))
                ->validationMessages(['unique' => 'This machine already has that code.'])
                ->helperText('Exactly as in the machine\'s output, for example HGB or 718-7.'),
            Select::make('analyte_id')->label('Our analyte')->required()->searchable()
                ->options(fn (): array => Analyte::orderBy('name')->get()->mapWithKeys(fn (Analyte $a): array => [$a->id => "{$a->name} ({$a->code})"])->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['machine', 'analyte']))
            ->columns([
                TextColumn::make('machine.name')->label('Machine')->sortable(),
                TextColumn::make('machine_code')->label('Machine code')->searchable(),
                TextColumn::make('analyte.name')->label('Our analyte')->searchable(),
            ])
            ->defaultSort('machine_code')
            ->filters([SelectFilter::make('machine_id')->label('Machine')->options(fn (): array => Machine::orderBy('name')->pluck('name', 'id')->all())])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMappings::route('/')];
    }
}
