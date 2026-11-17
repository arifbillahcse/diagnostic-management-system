<?php

declare(strict_types=1);

namespace Modules\People\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\People\Models\SalaryRun;
use Modules\People\Permissions;
use UnitEnum;

class SalaryRunResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = SalaryRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Salary runs';

    protected static ?string $modelLabel = 'salary run';

    protected static ?string $slug = 'staff/salary-runs';

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

    public static function statusColor(string $status): string
    {
        return ['draft' => 'warning', 'approved' => 'info', 'paid' => 'success', 'cancelled' => 'gray'][$status] ?? 'gray';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('run_no')->label('No.')->searchable(),
                TextColumn::make('month')->formatStateUsing(fn (SalaryRun $record): string => $record->monthLabel())->sortable(),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('lines_count')->counts('lines')->label('Staff')->alignEnd(),
                TextColumn::make('total_net')->label('Net pay')->alignEnd()->formatStateUsing(fn ($state): string => '৳ '.$state->formatted()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => SalaryRun::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => static::statusColor($state)),
            ])
            ->defaultSort('month', 'desc')
            ->recordUrl(fn (SalaryRun $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open')->label('Open')->url(fn (SalaryRun $record): string => static::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryRuns::route('/'),
            'view' => ViewSalaryRun::route('/{record}'),
        ];
    }
}
