<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Imaging\Enums;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Permissions;
use UnitEnum;

/** The imaging work list: every billed imaging test and where its report stands. */
class StudyResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Study::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Imaging';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Imaging reports';

    protected static ?string $modelLabel = 'imaging report';

    protected static ?string $pluralModelLabel = 'imaging reports';

    protected static ?string $slug = 'imaging/reports';

    public static function moduleKey(): string
    {
        return 'imaging';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::REPORTS_PRINT : null;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Study::whereIn('status', ['pending', 'draft', 'amending'])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        $tz = app(ClinicContext::class)->timezone();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'patient', 'signer']))
            ->columns([
                TextColumn::make('order.order_no')->label('Order')->searchable(),
                TextColumn::make('patient.name')->label('Patient')->searchable()
                    ->description(fn (Study $record): string => $record->patient?->patient_code ?? ''),
                TextColumn::make('study_name')->label('Imaging test')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, Study $record): string => Enums::status($state).($record->version > 1 && $state === 'final' ? " (v{$record->version})" : ''))
                    ->color(fn (string $state): string => Enums::color($state)),
                TextColumn::make('created_at')->label('Billed')->dateTime('d M Y, h:i A', $tz)->sortable(),
                TextColumn::make('signer.name')->label('Signed by')->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(BranchFilter::scope())
            ->filters([
                BranchFilter::make(),
                SelectFilter::make('state')->label('Show')->default('open')
                    ->options(['open' => 'Waiting for a report', 'final' => 'Signed', 'cancelled' => 'Cancelled'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereIn('status', ['pending', 'draft', 'amending']),
                        'final' => $query->where('status', 'final'),
                        'cancelled' => $query->where('status', 'cancelled'),
                        default => $query,
                    }),
            ])
            ->recordUrl(fn (Study $record): string => static::getUrl('report', ['record' => $record]))
            ->recordActions([
                Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)
                    ->visible(fn (Study $record): bool => $record->status === 'final' && Gate::allows(Permissions::REPORTS_PRINT, $record->branch_id))
                    ->url(fn (Study $record): string => route('imaging.print', ['order' => $record->order->public_id, 'study' => $record->public_id]), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudies::route('/'),
            'report' => ReportStudy::route('/{record}'),
        ];
    }
}
