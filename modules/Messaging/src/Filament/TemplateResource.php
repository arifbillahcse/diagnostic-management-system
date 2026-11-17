<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Models\Template;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\SmsText;
use Modules\Messaging\Support\Templates;
use UnitEnum;

/**
 * The clinic's wording for each message. The list is fixed; only the text
 * and the on/off switch can change.
 */
class TemplateResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Template::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Messaging';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Message wording';

    protected static ?string $modelLabel = 'message wording';

    protected static ?string $pluralModelLabel = 'message wording';

    protected static ?string $slug = 'messaging/wording';

    public static function moduleKey(): string
    {
        return 'messaging';
    }

    protected static function permissionFor(string $operation): ?string
    {
        // Nothing is created or deleted: the set of messages is fixed.
        return in_array($operation, ['viewAny', 'view', 'update'], true) ? Permissions::TEMPLATES_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')
                ->maxLength(255)
                ->columnSpanFull()
                ->visible(fn (?Template $record): bool => $record?->channel === Channel::Email)
                ->required(fn (?Template $record): bool => $record?->channel === Channel::Email),
            Textarea::make('body')
                ->label('Text')
                ->required()
                ->rows(5)
                ->maxLength(1000)
                ->columnSpanFull()
                ->helperText('You can use: {patient} {clinic} {phone} {bill_no} {total} {paid} {due} {delivery} {link}. An SMS of up to 160 plain characters is charged as one; longer or non-English text is charged as several.'),
            Toggle::make('is_active')->label('Send this message')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template_key')->label('Message')->formatStateUsing(fn (string $state): string => Templates::name($state)),
                TextColumn::make('channel')->formatStateUsing(fn (Channel $state): string => $state->label()),
                TextColumn::make('body')->label('Text')->wrap()
                    ->description(fn (Template $record): ?string => $record->channel === Channel::Sms
                        ? 'About '.SmsText::segments($record->body).' SMS part(s) before names and numbers are filled in'
                        : null),
                IconColumn::make('is_active')->label('On')->boolean(),
            ])
            ->defaultSort('template_key')
            ->paginated(false)
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTemplates::route('/')];
    }
}
