<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament\Samples;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Support\BranchFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Filament\Concerns;
use Modules\Laboratory\Models\Transfer;
use Modules\Laboratory\Permissions;
use UnitEnum;

/** Samples sent from collection centres to a laboratory, box by box. */
class TransferResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Transfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Sample transfers';

    protected static ?string $modelLabel = 'sample transfer';

    protected static ?string $slug = 'lab/transfers';

    public static function moduleKey(): string
    {
        return 'laboratory';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::WORK_VIEW : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (Transfer::exists() || DB::connection('clinic')->table('lab_routes')->exists());
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Transfer::where('status', 'in_transit')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        $tz = app(ClinicContext::class)->timezone();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fromBranch', 'toBranch', 'sender'])->withCount('samples'))
            ->columns([
                TextColumn::make('transfer_no')->label('No.')->searchable(),
                TextColumn::make('fromBranch.name')->label('From'),
                TextColumn::make('toBranch.name')->label('To'),
                TextColumn::make('samples_count')->label('Samples')->alignEnd(),
                TextColumn::make('sent_at')->label('Sent')->dateTime('d M, h:i A', $tz)->description(fn (Transfer $r): ?string => $r->sender?->name),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, Transfer $r): string => $state === 'received' ? 'Received '.$r->received_at?->timezone($tz)->format('d M, h:i A') : 'On the way')
                    ->color(fn (string $state): string => $state === 'received' ? 'success' : 'warning'),
            ])
            ->defaultSort('sent_at', 'desc')
            ->modifyQueryUsing(BranchFilter::scope(['from_branch_id', 'to_branch_id']))
            ->filters([BranchFilter::make(['from_branch_id', 'to_branch_id'])])
            ->recordActions([
                Action::make('list')->label('Courier list')->color('gray')->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Transfer $record): string => route('laboratory.print.transfer', $record->public_id), shouldOpenInNewTab: true),
                Action::make('receive')->label('Receive all')
                    ->visible(fn (Transfer $record): bool => $record->status === 'in_transit' && Gate::allows(Permissions::SAMPLES_RECEIVE, $record->to_branch_id))
                    ->requiresConfirmation()
                    ->modalDescription('Every sample still on the way in this transfer is received in the laboratory. To receive one by one, scan them on the Samples list instead.')
                    ->action(fn (Transfer $record) => Concerns::attempt(fn () => app(HandleSample::class)->receiveTransfer($record), 'Samples received')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTransfers::route('/')];
    }
}
