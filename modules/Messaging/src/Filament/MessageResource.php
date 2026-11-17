<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

use App\Core\Modules\GatesModuleResource;
use App\Core\Tenancy\ClinicContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Messaging\Actions\ManageMessages;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\MessagingException;
use Modules\Messaging\Support\Templates;
use UnitEnum;

/**
 * Every message sent to a patient, newest first. Read-only, apart from
 * sending a failed message again and stopping messages to a number.
 */
class MessageResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Message::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Messaging';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Message log';

    protected static ?string $modelLabel = 'message';

    protected static ?string $slug = 'messaging/log';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function moduleKey(): string
    {
        return 'messaging';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return $operation === 'viewAny' ? Permissions::LOG_VIEW : null;
    }

    private static function run(callable $work, string $success): void
    {
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (MessagingException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Not done')->body('You do not have permission to do this.')->send();
        }
    }

    public static function table(Table $table): Table
    {
        $mayManage = fn (): bool => Gate::allows(Permissions::MESSAGES_MANAGE);
        $stopped = fn (Message $record): bool => OptOut::where('recipient', $record->recipient)->exists();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('patient'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, h:i A', app(ClinicContext::class)->timezone()),
                TextColumn::make('recipient')->label('To')->searchable()
                    ->description(fn (Message $record): ?string => $record->patient?->name),
                TextColumn::make('template_key')->label('Message')
                    ->formatStateUsing(fn (string $state): string => Templates::name($state))
                    ->description(fn (Message $record): string => $record->channel->label().($record->channel === Channel::Sms ? " · {$record->segments} part(s)" : '')),
                TextColumn::make('body')->label('Text')->wrap()->limit(160),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (MessageStatus $state): string => $state->label())
                    ->color(fn (MessageStatus $state): string => match ($state) {
                        MessageStatus::Queued => 'warning',
                        MessageStatus::Sent => 'success',
                        MessageStatus::Failed => 'danger',
                    })
                    ->description(fn (Message $record): ?string => $record->error),
            ])
            ->filters([
                SelectFilter::make('status')->options(MessageStatus::options()),
                SelectFilter::make('channel')->options(Channel::options()),
            ])
            ->defaultSort('id', 'desc')
            // The log grows without end; counting every row for page numbers would be slow.
            ->paginationMode(PaginationMode::Simple)
            ->recordActions([
                Action::make('resend')
                    ->label('Send again')
                    ->visible(fn (Message $record): bool => $record->status === MessageStatus::Failed && $mayManage())
                    ->requiresConfirmation()
                    ->action(fn (Message $record) => static::run(fn () => app(ManageMessages::class)->resend($record), 'Message queued again')),
                Action::make('stop')
                    ->label('Stop messages')
                    ->color('danger')
                    ->visible(fn (Message $record): bool => $mayManage() && ! $stopped($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Message $record): string => "No more messages will be sent to {$record->recipient}. Use this when a patient asks, or the number is wrong.")
                    ->action(fn (Message $record) => static::run(fn () => app(ManageMessages::class)->stop($record->recipient), 'Messages stopped')),
                Action::make('allow')
                    ->label('Allow messages')
                    ->color('gray')
                    ->visible(fn (Message $record): bool => $mayManage() && $stopped($record))
                    ->requiresConfirmation()
                    ->action(fn (Message $record) => static::run(fn () => app(ManageMessages::class)->allow($record->recipient), 'Messages allowed again')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMessages::route('/')];
    }
}
