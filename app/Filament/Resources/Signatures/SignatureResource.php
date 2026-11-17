<?php

declare(strict_types=1);

namespace App\Filament\Resources\Signatures;

use App\Core\Auth\CorePermissions;
use App\Core\Auth\User;
use App\Core\Documents\ClinicFiles;
use App\Core\Documents\Signatory;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\Signatures\Pages\ManageSignatures;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** How each person who signs reports (lab or imaging) is named on paper. */
class SignatureResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Signatory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 46;

    protected static ?string $navigationLabel = 'Report signatures';

    protected static ?string $modelLabel = 'signature';

    protected static ?string $slug = 'signatures';

    public static function permission(): string
    {
        return CorePermissions::SETTINGS_MANAGE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('User')
                ->required()
                ->searchable()
                ->unique(ignoreRecord: true)
                ->options(fn (): array => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('The account this person signs in with. Their name below is printed on lab results they verify and imaging reports they sign.'),
            TextInput::make('display_name')->label('Name as printed')->required()->maxLength(255)->placeholder('Dr. Nasrin Sultana'),
            Textarea::make('qualifications')->label('Lines printed under the name')->rows(3)->maxLength(500)
                ->placeholder("MBBS, DMU (Ultrasound)\nConsultant Sonologist")->columnSpanFull(),
            FileUpload::make('signature_path')
                ->label('Signature image')
                ->disk(ClinicFiles::DISK)
                ->directory(fn (): string => app(ClinicFiles::class)->directory('signatures'))
                ->visibility('private')
                ->acceptedFileTypes(['image/png', 'image/jpeg'])
                ->maxSize(512)
                ->previewable(false)
                ->downloadable(false)
                ->openable(false)
                ->helperText('PNG or JPEG, up to 512 KB. A scan of the signature on white paper works well.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('display_name')->label('Printed name'),
                TextColumn::make('user.name')->label('User'),
                TextColumn::make('qualifications')->limit(60)->placeholder('-'),
                IconColumn::make('signature_path')->label('Signature')->boolean()->state(fn (Signatory $record): bool => filled($record->signature_path)),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSignatures::route('/')];
    }
}
