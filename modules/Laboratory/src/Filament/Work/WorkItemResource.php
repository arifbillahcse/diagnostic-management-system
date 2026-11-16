<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Work;

use App\Core\Modules\GatesModuleResource;
use App\Core\Patients\Patient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use UnitEnum;

/** The laboratory worklist: every test, by what needs doing next. */
class WorkItemResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = WorkItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Worklist';

    protected static ?string $modelLabel = 'test';

    protected static ?string $slug = 'lab/worklist';

    protected static ?string $recordRouteKeyName = 'public_id';

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

        $open = WorkItem::whereIn('status', [WorkStatus::Ready->value, WorkStatus::Entered->value, WorkStatus::Amending->value])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tests waiting for results or verification';
    }

    public static function statusColor(WorkStatus $status): string
    {
        return match ($status) {
            WorkStatus::Ready => 'warning',
            WorkStatus::Entered => 'info',
            WorkStatus::Released, WorkStatus::Verified => 'success',
            WorkStatus::Amending => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'sample', 'order']))
            ->columns([
                TextColumn::make('sample.accession_no')->label('Sample no.')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        $like = str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                        $q->whereIn('sample_id', Sample::query()->select('id')->where('accession_no', 'like', $like))
                            ->orWhereIn('patient_id', Patient::search($search)->select('id')->limit(200));
                    })),
                TextColumn::make('test_name')->label('Test')->weight('bold')->description(fn (WorkItem $record): ?string => $record->department),
                TextColumn::make('patient.name')->label('Patient')
                    ->description(fn (WorkItem $record): string => $record->patient->ageLabel().' / '.$record->patient->sex->label()),
                TextColumn::make('order.order_no')->label('Order'),
                TextColumn::make('created_at')->label('Ordered')->since(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (WorkStatus $state): string => $state->label())
                    ->color(fn (WorkStatus $state): string => static::statusColor($state))
                    ->description(fn (WorkItem $record): ?string => $record->pending_reason),
            ])
            ->searchPlaceholder('Scan a label, or type a patient')
            ->defaultSort('id')
            ->recordActions([
                Action::make('open')
                    ->label(fn (WorkItem $record): string => match ($record->status) {
                        WorkStatus::Ready, WorkStatus::Amending => 'Enter results',
                        WorkStatus::Entered => 'Review',
                        default => 'Open',
                    })
                    ->visible(fn (WorkItem $record): bool => $record->status !== WorkStatus::AwaitingSample)
                    ->url(fn (WorkItem $record): string => static::getUrl('results', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItems::route('/'),
            'results' => ResultsPage::route('/{record}'),
        ];
    }
}
