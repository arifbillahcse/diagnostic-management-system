<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Auth\CorePermissions;
use App\Core\Documents\ClinicFiles;
use App\Core\Modules\ModuleState;
use App\Core\Numbering\Actions\ChangeNumberFormats;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\NumberGenerator;
use App\Core\Settings\Actions\UpdateSettings;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The clinic's own details, shown on screens and printed documents.
 */
class ManageSettings extends Page
{
    /** Form field => setting name. */
    private const FIELDS = [
        'name' => 'clinic.name',
        'address' => 'clinic.address',
        'phone' => 'clinic.phone',
        'email' => 'clinic.email',
        'report_header' => 'clinic.report_header',
        'logo_path' => 'clinic.logo_path',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Clinic settings';

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows(CorePermissions::SETTINGS_MANAGE);
    }

    public function mount(Settings $settings): void
    {
        $this->form->fill(array_map(fn (string $name) => $settings->get($name), self::FIELDS) + $this->numberValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Clinic profile')
                    ->description('These details appear on invoices, receipts, and reports.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Clinic name')->required()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(60),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('address')->maxLength(255),
                        Textarea::make('report_header')
                            ->label('Report header text')
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Printed under the clinic name on reports, for example licence numbers.'),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->disk(ClinicFiles::DISK)
                            ->directory(fn (): string => app(ClinicFiles::class)->directory('branding'))
                            ->visibility('private')
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->maxSize(1024)
                            ->previewable(false)
                            ->downloadable(false)
                            ->openable(false)
                            ->columnSpanFull()
                            ->helperText('PNG or JPEG, up to 1 MB. Printed beside the clinic name on reports.'),
                    ]),
                Section::make('Number formats')
                    ->description('How bill, receipt, and other numbers look. A new format applies from the next number; numbers already issued do not change, and the count carries on where it is.')
                    ->collapsed()
                    ->columns(2)
                    ->schema($this->numberFields()),
            ]);
    }

    /** The numbers this clinic can format, hiding those of modules that are switched off. @return array<string, array{label: string, default: string, module: ?string}> */
    private function numberTypes(): array
    {
        $state = app(ModuleState::class);

        return array_filter(NumberGenerator::types(), fn (array $type): bool => $type['module'] === null || $state->isEnabled($type['module']));
    }

    /** @return array<string, string> form field => the format now in use */
    private function numberValues(): array
    {
        $values = [];

        foreach ($this->numberTypes() as $key => $type) {
            $values["num_{$key}"] = app(NumberGenerator::class)->format($key)->pattern;
        }

        return $values;
    }

    /** @return list<TextInput|Text> */
    private function numberFields(): array
    {
        $fields = [];

        foreach ($this->numberTypes() as $key => $type) {
            $fields[] = TextInput::make("num_{$key}")
                ->label($type['label'])
                ->required()
                ->maxLength(60)
                ->live(onBlur: true)
                ->helperText(function (Get $get) use ($key, $type): string {
                    try {
                        $example = (new NumberFormat((string) $get("num_{$key}")))->render(35, now(), 'MAIN');
                    } catch (\InvalidArgumentException) {
                        return 'Needs one counter such as {00001}. Usual built-in format: '.$type['default'];
                    }

                    return "Example: {$example}. Built-in: {$type['default']}";
                });
        }

        $fields[] = Text::make('You can use {branch} for the branch code, {yymm} for year and month (2611), {yymmdd} for the full date, {yy} {yyyy} {mm} {dd}, and one counter such as {00001} (the zeros set the width). The count restarts when the date part changes: with {yymm} each month, with {yymmdd} each day, with none never.')
            ->columnSpanFull();

        return $fields;
    }

    public function save(UpdateSettings $update): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (self::FIELDS as $field => $name) {
            $values[$name] = filled($state[$field] ?? null) ? $state[$field] : null;
        }

        $patterns = [];

        foreach (array_keys($this->numberTypes()) as $key) {
            $patterns[$key] = (string) ($state["num_{$key}"] ?? '');
        }

        try {
            app(ChangeNumberFormats::class)->handle($patterns);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title('Number formats not saved')->body($e->getMessage())->persistent()->send();

            return;
        }

        $update->handle($values);

        Notification::make()->success()->title('Settings saved')->send();
    }
}
