<?php

declare(strict_types=1);

namespace Modules\Demo\Filament;

use App\Core\Modules\GatesModuleAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Modules\Demo\DemoModule;
use Modules\Demo\Models\DemoNote;
use UnitEnum;

class DemoNoteResource extends Resource
{
    use GatesModuleAccess;

    protected static ?string $model = DemoNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Demo';

    protected static ?string $navigationLabel = 'Demo notes';

    protected static ?string $modelLabel = 'note';

    protected static ?string $slug = 'demo-notes';

    public static function moduleKey(): string
    {
        return 'demo';
    }

    public static function permissionKey(): ?string
    {
        return DemoModule::NOTES_MANAGE;
    }

    /** Every operation on this resource needs the module on and the permission held. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return static::moduleAllows() ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('body')->rows(4)->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('body')->limit(60)->placeholder('-'),
                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDemoNotes::route('/'),
        ];
    }
}
