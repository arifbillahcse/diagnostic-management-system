<?php

declare(strict_types=1);

namespace App\Filament\Resources\Patients;

use App\Core\Auth\CorePermissions;
use App\Core\Patients\DuplicateFinder;
use App\Core\Patients\Patient;
use App\Core\Patients\Sex;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class PatientResource extends Resource
{
    protected static ?string $model = Patient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    // The random public ID goes in the address, never the row number.
    protected static ?string $recordRouteKeyName = 'public_id';

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $permission = match ($action instanceof UnitEnum ? $action->name : $action) {
            'viewAny', 'view' => CorePermissions::PATIENTS_VIEW,
            'create' => CorePermissions::PATIENTS_CREATE,
            'update' => CorePermissions::PATIENTS_UPDATE,
            default => null, // patients are never deleted
        };

        return $permission !== null && Gate::allows($permission) ? Response::allow() : Response::deny();
    }

    /**
     * The fields shared by this resource and the "new patient" pop-up on
     * the order screen.
     *
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            Select::make('sex')->options(Sex::options())->required()->native(false),
            DatePicker::make('date_of_birth')
                ->label('Date of birth')
                ->maxDate(fn () => now(app(ClinicContext::class)->get()?->timezone)->toDateString())
                ->helperText('Leave empty and fill in the age when the patient does not know it.'),
            TextInput::make('age_years')->label('Age: years')->numeric()->minValue(0)->maxValue(130)->dehydrated(),
            TextInput::make('age_months')->label('Months')->numeric()->minValue(0)->maxValue(11),
            TextInput::make('age_days')->label('Days')->numeric()->minValue(0)->maxValue(31),
            TextInput::make('phone')
                ->tel()
                ->maxLength(30)
                ->live(onBlur: true)
                ->helperText(function (Get $get, ?Patient $record): ?string {
                    $matches = app(DuplicateFinder::class)->byPhone($get('phone'), $record?->id);

                    return $matches->isEmpty() ? null : 'Already registered with this phone: '
                        .$matches->map(fn (Patient $p): string => "{$p->name} ({$p->patient_code})")->implode(', ')
                        .'. Check this is not the same person.';
                }),
            Select::make('blood_group')->options(array_combine($g = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], $g))->native(false),
            TextInput::make('address')->maxLength(255)->columnSpanFull(),
            Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema(static::fields())->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient_code')->label('Code')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search)),
                TextColumn::make('name'),
                TextColumn::make('age')->label('Age / sex')
                    ->state(fn (Patient $record): string => $record->ageLabel().' / '.$record->sex->label()),
                TextColumn::make('phone')->placeholder('-'),
                TextColumn::make('created_at')->label('Registered')->date('d M Y'),
            ])
            ->searchPlaceholder('Code, start of name, or phone')
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPatients::route('/'),
            'create' => Pages\CreatePatient::route('/create'),
            'edit' => Pages\EditPatient::route('/{record}/edit'),
        ];
    }
}
