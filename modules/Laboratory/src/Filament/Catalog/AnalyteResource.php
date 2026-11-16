<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Laboratory\Enums\ResultType;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\ReferenceRange;
use Modules\Laboratory\Permissions;
use UnitEnum;

class AnalyteResource extends Resource
{
    use GatesModuleResource;

    private const NUMBER = 'regex:/^-?\d{1,12}(\.\d{1,6})?$/';

    protected static ?string $model = Analyte::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVariable;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Analytes and ranges';

    protected static ?string $slug = 'lab-setup/analytes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        // Analytes are made inactive, not deleted: old results refer to them.
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    private static function limit(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->rule(self::NUMBER)
            ->validationMessages(['regex' => 'Enter a number such as 13 or 13.5.'])
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : rtrim(rtrim(number_format((float) $state, 6, '.', ''), '0'), '.'));
    }

    public static function form(Schema $schema): Schema
    {
        $isNumeric = fn (Get $get): bool => $get('result_type') === ResultType::Numeric->value;
        $isOption = fn (Get $get): bool => $get('result_type') === ResultType::Option->value;

        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                Select::make('result_type')->label('Kind of result')->options(ResultType::options())
                    ->required()->native(false)->live()->default(ResultType::Numeric->value),
                TextInput::make('unit')->maxLength(40)->placeholder('g/dL'),
                TextInput::make('decimals')->label('Decimal places shown')->numeric()->minValue(0)->maxValue(6)->default(1)->required()->visible($isNumeric),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                TagsInput::make('options')->label('Allowed answers')->placeholder('Type an answer and press Enter')
                    ->required($isOption)->visible($isOption)->live()->columnSpan(2),
                CheckboxList::make('abnormal_options')->label('Answers flagged as abnormal')
                    ->options(fn (Get $get): array => array_combine($get('options') ?? [], $get('options') ?? []))
                    ->visible(fn (Get $get): bool => $isOption($get) && filled($get('options')))->columns(4)->columnSpanFull(),
            ]),

            Section::make('Reference ranges')
                ->description('The system picks the range for the patient\'s sex and their age on the day the sample was collected. A result equal to a limit counts as normal. A range changed here applies to new results only.')
                ->columnSpanFull()
                ->visible($isNumeric)
                ->schema([
                    Repeater::make('ranges')
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(6)
                        ->addActionLabel('Add a range')
                        ->defaultItems(0)
                        ->schema([
                            Select::make('sex')->options(['any' => 'Any', 'male' => 'Male', 'female' => 'Female'])->default('any')->required()->native(false),
                            TextInput::make('age_min_days')->label('Age from (days)')->numeric()->minValue(0)->default(0)->required()
                                ->helperText('1 year = 365 days'),
                            TextInput::make('age_max_days')->label('Age to (days)')->numeric()->minValue(0)->default(ReferenceRange::MAX_AGE_DAYS)->required()
                                ->gte('age_min_days')->helperText('18 years = 6570'),
                            static::limit('low', 'Low limit'),
                            static::limit('high', 'High limit'),
                            TextInput::make('text_range')->label('Printed as')->maxLength(255)->placeholder('Optional'),
                            static::limit('critical_low', 'Critical low'),
                            static::limit('critical_high', 'Critical high'),
                            DatePicker::make('effective_from')->label('In use from'),
                            DatePicker::make('effective_to')->label('In use until'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('ranges'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('result_type')->label('Kind')->formatStateUsing(fn (ResultType $state): string => $state->label()),
                TextColumn::make('unit')->placeholder('-'),
                TextColumn::make('ranges_count')->label('Ranges'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnalytes::route('/'),
            'create' => CreateAnalyte::route('/create'),
            'edit' => EditAnalyte::route('/{record}/edit'),
        ];
    }
}
