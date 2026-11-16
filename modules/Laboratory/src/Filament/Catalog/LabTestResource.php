<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Catalog;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Service;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\SampleType;
use Modules\Laboratory\Permissions;
use UnitEnum;

/**
 * The laboratory's set-up for each billable lab test: which sample it
 * needs and which values it reports.
 */
class LabTestResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = LabTest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Lab tests';

    protected static ?string $slug = 'lab-setup/tests';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::CATALOG_MANAGE : null;
    }

    /** Billable lab tests that have no laboratory set-up yet. */
    public static function notSetUp(): Builder
    {
        return Service::query()
            ->where('type', ServiceType::LabTest->value)
            ->where('is_active', true)
            ->whereNotIn('id', LabTest::query()->select('service_id'));
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $missing = static::notSetUp()->count();

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lab tests on the price list that still need a laboratory set-up';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                Select::make('service_id')
                    ->label('Test on the price list')
                    ->required()
                    ->searchable()
                    ->disabledOn('edit')
                    ->options(fn (?Model $record): array => Service::query()
                        ->where('type', ServiceType::LabTest->value)
                        ->where(fn ($q) => $q->whereNotIn('id', LabTest::query()->select('service_id'))->orWhere('id', $record?->service_id))
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Service $s): array => [$s->id => "{$s->name} ({$s->code})"])->all())
                    ->helperText('Only lab tests without a set-up are listed. Add the test under Catalog first.'),
                Select::make('sample_type_id')->label('Sample needed')->required()->native(false)
                    ->options(fn (): array => SampleType::orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('department')->maxLength(255)->placeholder('Haematology')
                    ->datalist(fn (): array => LabTest::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all()),
                TextInput::make('turnaround_hours')->label('Usual turnaround (hours)')->numeric()->minValue(0)->maxValue(2000),
                TextInput::make('method')->maxLength(255)->placeholder('Printed on the report, optional'),
                Textarea::make('report_notes')->label('Note printed under the results')->rows(2)->maxLength(2000),
            ]),

            Section::make('Values reported')
                ->description('In the order they appear on the report. Drag to reorder.')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('analyteLinks')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->minItems(1)
                        ->columns(4)
                        ->addActionLabel('Add a value')
                        ->schema([
                            Select::make('analyte_id')->label('Analyte')->required()->searchable()->distinct()->columnSpan(2)
                                ->options(fn (): array => Analyte::where('is_active', true)->orderBy('name')->get()
                                    ->mapWithKeys(fn (Analyte $a): array => [$a->id => "{$a->name} ({$a->code})"])->all()),
                            TextInput::make('group_label')->label('Heading above it')->maxLength(255)->placeholder('Optional'),
                            Toggle::make('is_required')->label('Must be filled')->default(true)->inline(false),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'sampleType'])->withCount('analyteLinks'))
            ->columns([
                TextColumn::make('service.name')->label('Test')->searchable(),
                TextColumn::make('department')->placeholder('-'),
                TextColumn::make('sampleType.name')->label('Sample'),
                TextColumn::make('analyte_links_count')->label('Values'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabTests::route('/'),
            'create' => CreateLabTest::route('/create'),
            'edit' => EditLabTest::route('/{record}/edit'),
        ];
    }
}
