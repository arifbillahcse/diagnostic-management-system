<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Core\Audit\AuditLog;
use App\Core\Auth\CorePermissions;
use App\Core\Tenancy\ClinicContext;
use App\Filament\Concerns\AuthorizesWithPermission;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Read-only view of the audit trail. */
class AuditLogResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?string $slug = 'audit-log';

    public static function permission(): string
    {
        return CorePermissions::AUDIT_VIEW;
    }

    protected static function allows(string $action, ?Model $record): bool
    {
        return in_array($action, ['viewAny', 'view'], true);
    }

    private static function timezone(): string
    {
        return app(ClinicContext::class)->get()?->timezone ?? 'UTC';
    }

    private static function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('occurred_at')->label('When')->dateTime('d M Y, h:i:s A', static::timezone()),
            TextEntry::make('actor_name')->label('Who'),
            TextEntry::make('action'),
            TextEntry::make('entity')->label('Record')
                ->state(fn (AuditLog $record): string => $record->entity_type ? class_basename($record->entity_type).' #'.$record->entity_id : '-'),
            TextEntry::make('ip')->label('IP address')->placeholder('-'),
            TextEntry::make('user_agent')->label('Browser')->placeholder('-'),
            TextEntry::make('before_json')->label('Before')->state(fn (AuditLog $record): ?string => static::json($record->before))
                ->placeholder('-')->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap']),
            TextEntry::make('after_json')->label('After')->state(fn (AuditLog $record): ?string => static::json($record->after))
                ->placeholder('-')->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap']),
            TextEntry::make('context_json')->label('Details')->state(fn (AuditLog $record): ?string => static::json($record->context))
                ->placeholder('-')->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap'])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, h:i A', static::timezone()),
                TextColumn::make('actor_name')->label('Who')->searchable(),
                TextColumn::make('action')->badge()->color('gray')->searchable(),
                TextColumn::make('entity')->label('Record')
                    ->state(fn (AuditLog $record): string => $record->entity_type ? class_basename($record->entity_type).' #'.$record->entity_id : '-'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
