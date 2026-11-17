<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Models\Account;
use Modules\People\Actions\ManageAdvances;
use Modules\People\Models\Advance;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\FinanceLink;
use UnitEnum;

class AdvanceResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Advance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Advances';

    protected static ?string $modelLabel = 'advance';

    protected static ?string $slug = 'staff/advances';

    public static function moduleKey(): string
    {
        return 'people';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return match ($operation) {
            'viewAny', 'view' => Permissions::SALARY_MANAGE,
            default => null,
        };
    }

    /** @return list<Field> */
    public static function giveFields(): array
    {
        $today = now(app(ClinicContext::class)->timezone())->toDateString();

        return array_values(array_filter([
            Select::make('staff_id')->label('Staff member')->required()->searchable()
                ->options(fn (): array => Staff::current()->orderBy('name')->get()
                    ->mapWithKeys(fn (Staff $s): array => [$s->id => "{$s->name} ({$s->staff_no})"])->all()),
            TextInput::make('amount')->prefix('৳')->required()
                ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Enter an amount such as 2000.']),
            DatePicker::make('given_on')->label('Given on')->required()->native(false)->maxDate($today)->default($today),
            TextInput::make('reason')->required()->maxLength(255)->placeholder('Family need'),
            app(FinanceLink::class)->on()
                ? Select::make('account_id')->label('Paid from')->required()->native(false)
                    ->options(fn (): array => Account::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Recorded in Finance as an expense, "Staff advances".')
                : null,
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('advance_no')->label('No.')->searchable(),
                TextColumn::make('staff.name')->label('Staff')->searchable(),
                TextColumn::make('given_on')->label('Given on')->date('d M Y')->sortable(),
                TextColumn::make('amount')->alignEnd()->formatStateUsing(fn ($state): string => '৳ '.$state->formatted()),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Advance $record): string => $record->isVoid() ? 'Void' : 'Given')
                    ->color(fn (Advance $record): string => $record->isVoid() ? 'gray' : 'success'),
            ])
            ->defaultSort('given_on', 'desc')
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([
                BranchFilter::make(), SelectFilter::make('staff_id')->label('Staff')->relationship('staff', 'name')])
            ->recordActions([
                Action::make('void')->label('Void')->color('danger')
                    ->visible(fn (Advance $record): bool => ! $record->isVoid() && Gate::allows(Permissions::ADVANCES_MANAGE, $record->branch_id))
                    ->modalDescription('For an advance entered by mistake. Its expense in Finance is voided too.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Action $action, Advance $record, array $data) => Guard::run($action, fn () => app(ManageAdvances::class)->void($record, (string) $data['reason']), 'Advance voided')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAdvances::route('/')];
    }
}
