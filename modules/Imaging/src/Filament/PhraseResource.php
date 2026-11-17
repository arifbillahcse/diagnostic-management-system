<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

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
use Modules\Imaging\Models\Phrase;
use Modules\Imaging\Permissions;
use UnitEnum;

class PhraseResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Phrase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Imaging';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Phrases';

    protected static ?string $slug = 'imaging/phrases';

    public static function moduleKey(): string
    {
        return 'imaging';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::TEMPLATES_MANAGE : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(100)->unique(ignoreRecord: true)->placeholder('No free fluid'),
            Textarea::make('body')->label('Sentence')->required()->rows(3)->maxLength(1000),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('body')->label('Sentence')->limit(80),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('title')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePhrases::route('/')];
    }
}
