<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use App\Core\Modules\GatesModuleResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Actions\CopyTemplate;
use Modules\Imaging\Enums;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;
use UnitEnum;

class TemplateResource extends Resource
{
    use GatesModuleResource;

    protected static ?string $model = Template::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Imaging';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Report templates';

    protected static ?string $modelLabel = 'report template';

    protected static ?string $slug = 'imaging/templates';

    public static function moduleKey(): string
    {
        return 'imaging';
    }

    protected static function permissionFor(string $operation): ?string
    {
        return in_array($operation, ['viewAny', 'view', 'create', 'update'], true) ? Permissions::TEMPLATES_MANAGE : null;
    }

    /** @return array<int, string> */
    public static function imagingServices(): array
    {
        return Service::where('type', ServiceType::Imaging->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The rich editor with simple formatting only (no links, images or code). */
    public static function editor(string $name, string $label): RichEditor
    {
        return RichEditor::make($name)->label($label)
            ->toolbarButtons([['bold', 'italic', 'underline'], ['h3'], ['bulletList', 'orderedList'], ['table'], ['undo', 'redo']])
            ->fileAttachments(false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('service_id')->label('Imaging test')->required()->searchable()->options(fn (): array => static::imagingServices())
                ->helperText('Only services of the type "Imaging" are listed.'),
            TextInput::make('name')->required()->maxLength(255)->placeholder('Normal')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('service_id', $get('service_id')))
                ->validationMessages(['unique' => 'This test already has a template with that name.']),
            Select::make('sex')->label('For')->options(Enums::SEXES)->default('any')->required()->native(false),
            TextInput::make('sort_order')->label('Order in the list')->numeric()->default(0)->minValue(0)->maxValue(999),
            static::editor('findings', 'Findings')->required()->columnSpanFull(),
            static::editor('impression', 'Impression')->required()->columnSpanFull(),
            Textarea::make('advice')->rows(2)->maxLength(2000)->columnSpanFull(),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('service'))
            ->columns([
                TextColumn::make('service.name')->label('Imaging test')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('sex')->label('For')->formatStateUsing(fn (string $state): string => Enums::SEXES[$state] ?? $state),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('service_id')
            ->filters([SelectFilter::make('service_id')->label('Imaging test')->options(fn (): array => static::imagingServices())])
            ->recordActions([
                EditAction::make()->modalWidth('5xl'),
                Action::make('copy')->label('Copy to another test')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                    ->fillForm(fn (Template $record): array => ['name' => $record->name])
                    ->schema([
                        Select::make('service_id')->label('Imaging test')->required()->searchable()->options(fn (): array => static::imagingServices()),
                        TextInput::make('name')->required()->maxLength(255),
                    ])
                    ->action(function (Action $action, Template $record, array $data): void {
                        Guard::run($action, fn () => app(CopyTemplate::class)->handle($record, (int) $data['service_id'], (string) $data['name']), 'Template copied');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTemplates::route('/')];
    }
}
