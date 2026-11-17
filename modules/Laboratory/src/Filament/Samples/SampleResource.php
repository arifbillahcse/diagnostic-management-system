<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Samples;

use App\Core\Modules\GatesModuleResource;
use App\Core\Patients\Patient;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Filament\Concerns;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Permissions;
use UnitEnum;

class SampleResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Sample::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'lab/samples';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::WORK_VIEW : null;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $waiting = Sample::whereIn('status', [SampleStatus::Pending->value, SampleStatus::Rejected->value])->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Samples to collect';
    }

    public static function statusColor(SampleStatus $status): string
    {
        return match ($status) {
            SampleStatus::Pending => 'warning',
            SampleStatus::Rejected => 'danger',
            SampleStatus::Completed => 'success',
            SampleStatus::Cancelled => 'gray',
            default => 'info',
        };
    }

    public static function table(Table $table): Table
    {
        $can = fn (string $permission, Sample $record): bool => Gate::allows($permission, $record->branch_id);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'sampleType', 'workItems', 'order']))
            ->columns([
                TextColumn::make('accession_no')->label('Sample no.')->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        // A scanned barcode is the whole number; typing matches from its start.
                        $q->where('accession_no', 'like', str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%')
                            ->orWhereIn('patient_id', Patient::search($search)->select('id'));
                    })),
                TextColumn::make('patient.name')->label('Patient')->description(fn (Sample $record): ?string => $record->patient?->patient_code),
                TextColumn::make('sampleType.name')->label('Sample')->description(fn (Sample $record): ?string => $record->sampleType?->container),
                TextColumn::make('tests')->state(fn (Sample $record): string => $record->workItems->pluck('test_name')->implode(', '))->wrap(),
                TextColumn::make('created_at')->label('Ordered')->dateTime('d M, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SampleStatus $state): string => $state->label())
                    ->color(fn (SampleStatus $state): string => static::statusColor($state))
                    ->description(fn (Sample $record): ?string => $record->status === SampleStatus::Rejected ? $record->rejection_reason : null),
            ])
            ->searchPlaceholder('Scan a label, or type a patient')
            ->filters([
                SelectFilter::make('status')->options(SampleStatus::options())->multiple()
                    ->default([SampleStatus::Pending->value, SampleStatus::Collected->value, SampleStatus::Rejected->value]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('collect')
                    ->visible(fn (Sample $record): bool => in_array($record->status, [SampleStatus::Pending, SampleStatus::Rejected], true) && $can(Permissions::SAMPLES_COLLECT, $record))
                    ->fillForm(fn (Sample $record): array => ['in_lab' => $can(Permissions::SAMPLES_RECEIVE, $record)])
                    ->schema(fn (Sample $record): array => $can(Permissions::SAMPLES_RECEIVE, $record) ? [
                        Toggle::make('in_lab')->label('The sample is already in the laboratory')
                            ->helperText('Switch off when samples are collected elsewhere and carried to the lab later.'),
                    ] : [])
                    ->modalHeading(fn (Sample $record): string => "Collect {$record->sampleType->name} from {$record->patient->name}")
                    ->modalDescription('Check the patient\'s name and the label on the container before confirming.')
                    ->modalSubmitActionLabel('Collected')
                    ->action(fn (Sample $record, array $data) => Concerns::attempt(
                        fn () => app(HandleSample::class)->collect($record, (bool) ($data['in_lab'] ?? false)),
                        'Sample collected',
                    )),
                Action::make('receive')
                    ->label('Receive in lab')
                    ->visible(fn (Sample $record): bool => $record->status === SampleStatus::Collected && $can(Permissions::SAMPLES_RECEIVE, $record))
                    ->action(fn (Sample $record) => Concerns::attempt(fn () => app(HandleSample::class)->receive($record), 'Sample received')),
                Action::make('label')
                    ->label('Print label')
                    ->color('gray')
                    ->visible(fn (Sample $record): bool => $record->status !== SampleStatus::Cancelled)
                    ->url(fn (Sample $record): string => route('laboratory.print.label', $record->public_id), shouldOpenInNewTab: true),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (Sample $record): bool => in_array($record->status, [SampleStatus::Collected, SampleStatus::Received, SampleStatus::InProcess], true) && $can(Permissions::SAMPLES_REJECT, $record))
                    ->modalDescription('The tests on this sample will wait for a new collection. Results entered from it but not yet verified are discarded.')
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)->placeholder('Clotted, too little, wrong container')])
                    ->action(fn (Sample $record, array $data) => Concerns::attempt(fn () => app(HandleSample::class)->reject($record, $data['reason']), 'Sample rejected')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSamples::route('/')];
    }
}
