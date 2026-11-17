<?php

declare(strict_types=1);

namespace Modules\Analyzers\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Message;
use Modules\Analyzers\Permissions;
use UnitEnum;

/** The machine inbox: every message and whether its values were saved. */
class MessageResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Message::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Lab machines';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Machine inbox';

    protected static ?string $modelLabel = 'machine message';

    protected static ?string $pluralModelLabel = 'machine inbox';

    protected static ?string $slug = 'lab-machines/inbox';

    public static function moduleKey(): string
    {
        return 'analyzers';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view'], true) ? Permissions::INBOX_RESOLVE : null;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Message::whereIn('status', ['partial', 'unmatched', 'failed'])->where('received_at', '>=', now()->subDays(7))->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $tz = app(ClinicContext::class)->timezone();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('machine')->withCount([
                'results as saved_count' => fn ($q) => $q->where('status', 'applied'),
                'results as unsaved_count' => fn ($q) => $q->where('status', '!=', 'applied'),
            ]))
            ->columns([
                TextColumn::make('received_at')->label('Received')->dateTime('d M Y, h:i A', $tz)->sortable(),
                TextColumn::make('machine.name')->label('Machine'),
                TextColumn::make('sample_codes')->label('Sample')->placeholder('-')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => Message::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => Message::color($state)),
                TextColumn::make('saved_count')->label('Saved')->alignEnd(),
                TextColumn::make('unsaved_count')->label('Not saved')->alignEnd(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('state')->label('Show')->default('attention')
                    ->options(['attention' => 'Needs attention', 'applied' => 'Saved', 'all' => 'Everything'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'attention' => $query->whereIn('status', ['partial', 'unmatched', 'failed']),
                        'applied' => $query->where('status', 'applied'),
                        default => $query,
                    }),
                SelectFilter::make('machine_id')->label('Machine')->options(fn (): array => Machine::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordUrl(fn (Message $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessages::route('/'),
            'view' => ViewMessage::route('/{record}'),
        ];
    }
}
