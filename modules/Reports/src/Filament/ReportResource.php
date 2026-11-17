<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Money\Money;
use App\Core\Patients\Patient;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use Modules\Reports\Permissions;
use UnitEnum;

/**
 * Orders that have at least one verified result: the list the front desk
 * prints reports from.
 */
class ReportResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $modelLabel = 'report';

    protected static ?string $slug = 'lab/reports';

    public static function moduleKey(): string
    {
        return 'reports';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::REPORTS_VIEW : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // Written as a per-order lookup on purpose. As "WHERE EXISTS" or
            // "IN", MySQL first builds the set of every order that has a
            // released result (hundreds of thousands) before taking ten.
            ->whereRaw('(select 1 from lab_work_items where lab_work_items.order_id = fd_orders.id and lab_work_items.status = ? limit 1) = 1', [WorkStatus::Released->value])
            ->with(['patient', 'invoice']);
    }

    /** "2 of 3 ready", counting only tests that were not cancelled. */
    private static function readiness(Order $order): string
    {
        $statuses = WorkItem::where('order_id', $order->id)->where('status', '!=', WorkStatus::Cancelled->value)->pluck('status');
        $ready = $statuses->filter(fn ($s): bool => ($s instanceof WorkStatus ? $s : WorkStatus::from($s)) === WorkStatus::Released)->count();

        return $ready === $statuses->count() ? 'All ready' : "{$ready} of {$statuses->count()} ready";
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_no')->label('Order')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        $q->where('order_no', 'like', str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%')
                            ->orWhereIn('patient_id', Patient::search($search)->select('id'));
                    })),
                TextColumn::make('ordered_at')->label('Date')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('patient.name')->label('Patient')->description(fn (Order $record): ?string => $record->patient?->phone),
                TextColumn::make('readiness')->label('Results')->badge()
                    ->state(fn (Order $record): string => static::readiness($record))
                    ->color(fn (string $state): string => $state === 'All ready' ? 'success' : 'warning'),
                TextColumn::make('invoice.due_amount')->label('Due')->alignEnd()
                    ->formatStateUsing(fn (?Money $state): string => $state === null || ! $state->isPositive() ? '-' : '৳ '.$state->formatted())
                    ->color(fn (?Money $state): ?string => $state?->isPositive() ? 'danger' : null),
            ])
            ->searchPlaceholder('Order number or patient')
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([BranchFilter::make()])
            ->defaultSort('id', 'desc')
            // This list grows without limit. Counting it on every page load is slow,
            // so it shows "next" and "previous" without a total.
            ->paginationMode(PaginationMode::Simple)
            ->recordActions([
                Action::make('print')
                    ->label('Print report')
                    ->visible(fn (Order $record): bool => Gate::allows(Permissions::REPORTS_PRINT, $record->branch_id))
                    ->url(fn (Order $record): string => route('reports.print', $record->public_id), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
