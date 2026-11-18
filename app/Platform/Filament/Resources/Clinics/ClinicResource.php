<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Clinics;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Licence;
use App\Core\Tenancy\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Every clinic on the platform, with its plan and subscription. */
class ClinicResource extends Resource
{
    protected static ?string $model = Clinic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'clinics';

    public static function statusColor(ClinicStatus $status): string
    {
        return match ($status) {
            ClinicStatus::Active => 'success',
            ClinicStatus::Trial => 'info',
            ClinicStatus::Overdue => 'warning',
            ClinicStatus::ReadOnly, ClinicStatus::Suspended => 'danger',
            ClinicStatus::Closed => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        $licence = app(Licence::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('plan'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Clinic $r): ?string => $r->domains()->orderByDesc('is_primary')->value('domain')),
                TextColumn::make('plan.name')->label('Plan')->placeholder('-'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ClinicStatus $state): string => $state->label())
                    ->color(fn (ClinicStatus $state): string => static::statusColor($state)),
                TextColumn::make('covered')->label('Covered until')
                    ->state(fn (Clinic $r): string => $licence->endsOn($r)?->format('d M Y') ?? 'No end')
                    ->description(fn (Clinic $r): ?string => ($d = $licence->daysLeft($r)) === null ? null : ($d >= 0 ? "{$d} day(s) left" : abs($d).' day(s) ago')),
                TextColumn::make('paid_until')->label('Paid until')->date('d M Y')->placeholder('Not paid yet')->sortable(),
                TextColumn::make('created_at')->label('Since')->date('d M Y')->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(collect(ClinicStatus::cases())->mapWithKeys(fn (ClinicStatus $s): array => [$s->value => $s->label()])->all()),
                SelectFilter::make('plan_id')->label('Plan')->options(fn (): array => Plan::orderBy('sort_order')->pluck('name', 'id')->all()),
            ])
            ->recordUrl(fn (Clinic $r): string => static::getUrl('view', ['record' => $r]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClinics::route('/'),
            'view' => ViewClinic::route('/{record}'),
        ];
    }
}
