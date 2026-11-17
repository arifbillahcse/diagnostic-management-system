<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Auth\User;
use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\People\Actions\ManageStaff;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\AdvanceBook;
use Modules\People\Support\Branches;
use UnitEnum;

class StaffResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $slug = 'staff';

    public static function moduleKey(): string
    {
        return 'people';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::STAFF_VIEW,
            default => null,
        };
    }

    public static function canSeeSalaries(): bool
    {
        return Gate::allows(Permissions::SALARY_MANAGE);
    }

    private static function today(): string
    {
        return now(app(ClinicContext::class)->timezone())->toDateString();
    }

    /** @return list<Component|Field> */
    public static function detailFields(?Staff $staff = null): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('designation')->required()->maxLength(100)->placeholder('Lab technician, receptionist, cleaner'),
            TextInput::make('phone')->tel()->maxLength(30),
            Select::make('branch_id')->label('Branch')->required()->native(false)
                ->options(fn (): array => Branches::options(Permissions::STAFF_MANAGE)),
            DatePicker::make('joined_on')->label('Joining date')->required()->native(false)->maxDate(self::today()),
            Select::make('user_id')->label('Sign-in account (if any)')->native(false)->searchable()
                ->options(fn (): array => User::query()
                    ->whereNotIn('id', Staff::whereNotNull('user_id')->when($staff, fn ($q) => $q->whereKeyNot($staff->id))->pluck('user_id'))
                    ->orderBy('name')->pluck('name', 'id')->all()),
            Textarea::make('notes')->rows(2)->maxLength(1000),
        ];
    }

    public static function salaryField(string $name = 'monthly_salary', string $label = 'Monthly salary'): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('৳')->required()
            ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter an amount such as 15000 or 15000.50.']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('staff_no')->label('No.')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('designation')->searchable(),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('phone')->placeholder('-'),
                TextColumn::make('joined_on')->label('Joined')->date('d M Y')->sortable(),
                TextColumn::make('monthly_salary')->label('Salary')->alignEnd()
                    ->formatStateUsing(fn ($state): string => '৳ '.$state->formatted())
                    ->visible(fn (): bool => static::canSeeSalaries()),
                TextColumn::make('advance_owed')->label('Advance owed')->alignEnd()
                    ->state(fn (Staff $record): string => ($owed = app(AdvanceBook::class)->outstanding($record->id))->isZero() ? '-' : '৳ '.$owed->formatted())
                    ->visible(fn (): bool => static::canSeeSalaries()),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Staff $record): string => $record->hasLeft() ? 'Left '.$record->left_on->format('d M Y') : 'Working')
                    ->color(fn (Staff $record): string => $record->hasLeft() ? 'gray' : 'success'),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([
                BranchFilter::make(),
                SelectFilter::make('state')->label('Status')->default('working')
                    ->options(['working' => 'Working', 'left' => 'Left'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'working' => $query->whereNull('left_on'),
                        'left' => $query->whereNotNull('left_on'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label('Edit details')->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (Staff $record): bool => Gate::allows(Permissions::STAFF_MANAGE, $record->branch_id))
                        ->fillForm(fn (Staff $record): array => $record->only(['name', 'designation', 'phone', 'branch_id', 'user_id', 'notes']) + ['joined_on' => $record->joined_on->toDateString()])
                        ->schema(fn (Staff $record): array => static::detailFields($record))
                        ->action(fn (Action $action, Staff $record, array $data) => Guard::run($action, fn () => app(ManageStaff::class)->update($record, $data), 'Details saved')),

                    Action::make('salary')->label('Change salary')->icon(Heroicon::OutlinedBanknotes)
                        ->visible(fn (Staff $record): bool => ! $record->hasLeft() && Gate::allows(Permissions::SALARY_MANAGE, $record->branch_id))
                        ->modalDescription(fn (Staff $record): string => 'Now ৳ '.$record->monthly_salary->formatted().' a month. The old salary stays in the history.')
                        ->fillForm(fn (): array => ['effective_from' => now(app(ClinicContext::class)->timezone())->startOfMonth()->toDateString()])
                        ->schema([
                            static::salaryField('monthly_salary', 'New monthly salary'),
                            DatePicker::make('effective_from')->label('Starts from')->required()->native(false)->maxDate(self::today()),
                            TextInput::make('reason')->required()->maxLength(255)->placeholder('Yearly increment'),
                        ])
                        ->action(fn (Action $action, Staff $record, array $data) => Guard::run($action, fn () => app(ManageStaff::class)
                            ->changeSalary($record, (string) $data['monthly_salary'], (string) $data['effective_from'], (string) $data['reason']), 'Salary changed')),

                    Action::make('history')->label('Salary history')->icon(Heroicon::OutlinedClock)
                        ->visible(fn (Staff $record): bool => Gate::allows(Permissions::SALARY_MANAGE, $record->branch_id))
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(fn (Staff $record) => view('people::salary-history', ['changes' => $record->salaryChanges()->get()])),

                    Action::make('left')->label('Mark as left')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                        ->visible(fn (Staff $record): bool => ! $record->hasLeft() && Gate::allows(Permissions::STAFF_MANAGE, $record->branch_id))
                        ->fillForm(fn (): array => ['left_on' => self::today()])
                        ->schema([
                            DatePicker::make('left_on')->label('Last working day')->required()->native(false),
                            TextInput::make('reason')->required()->maxLength(255)->placeholder('Resigned'),
                        ])
                        ->action(fn (Action $action, Staff $record, array $data) => Guard::run($action, fn () => app(ManageStaff::class)
                            ->markLeft($record, (string) $data['left_on'], (string) $data['reason']), 'Marked as left')),

                    Action::make('rejoin')->label('Mark as back')->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Staff $record): bool => $record->hasLeft() && Gate::allows(Permissions::STAFF_MANAGE, $record->branch_id))
                        ->schema([TextInput::make('reason')->required()->maxLength(255)->placeholder('Marked as left by mistake')])
                        ->action(fn (Action $action, Staff $record, array $data) => Guard::run($action, fn () => app(ManageStaff::class)
                            ->rejoin($record, (string) $data['reason']), 'Marked as working')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStaff::route('/')];
    }
}
