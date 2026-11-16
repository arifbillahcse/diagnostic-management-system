<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Reports\Permissions;
use UnitEnum;

class ReportSettings extends Page
{
    use GatesModuleAccess;

    /** Form field => [setting name, default]. */
    private const FIELDS = [
        'letterhead' => [Permissions::SETTING_LETTERHEAD, 'print'],
        'top_margin_mm' => [Permissions::SETTING_TOP_MARGIN, 40],
        'bottom_margin_mm' => [Permissions::SETTING_BOTTOM_MARGIN, 25],
        'footer_text' => [Permissions::SETTING_FOOTER, null],
        'block_print_when_due' => [Permissions::SETTING_BLOCK_WHEN_DUE, false],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Report layout';

    protected static ?string $title = 'Report layout';

    protected static ?string $slug = 'lab-setup/report-layout';

    protected string $view = 'reports::settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function moduleKey(): string
    {
        return 'reports';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::SETTINGS_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    public function mount(Settings $settings): void
    {
        $this->form->fill(array_map(fn (array $field) => $settings->get($field[0], $field[1]), self::FIELDS));
    }

    public function form(Schema $schema): Schema
    {
        $preprinted = fn (Get $get): bool => $get('letterhead') === 'preprinted';

        return $schema->statePath('data')->components([
            Section::make('Paper')->columns(2)->schema([
                Radio::make('letterhead')
                    ->label('Clinic header')
                    ->options([
                        'print' => 'Print the clinic name, address, and logo on plain paper',
                        'preprinted' => 'We use pre-printed letterhead paper; leave space for it',
                    ])
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('top_margin_mm')->label('Space at the top (mm)')->numeric()->minValue(5)->maxValue(120)->required($preprinted)->visible($preprinted)
                    ->helperText('Measure from the top edge to where the printing may start.'),
                TextInput::make('bottom_margin_mm')->label('Space at the bottom (mm)')->numeric()->minValue(5)->maxValue(80)->required($preprinted)->visible($preprinted),
                Textarea::make('footer_text')->label('Footer line')->rows(2)->maxLength(200)->columnSpanFull()
                    ->placeholder('Results relate only to the sample tested.'),
            ]),
            Section::make('Handing over reports')->schema([
                Toggle::make('block_print_when_due')
                    ->label('Do not print a report while its invoice has a due')
                    ->helperText('Users with the "print while there is a due" permission can still print.'),
            ]),
        ]);
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $state = $this->form->getState();
        $before = $after = [];

        foreach (self::FIELDS as $field => [$name, $default]) {
            if (! array_key_exists($field, $state)) {
                continue; // hidden fields keep their stored value
            }

            $new = match ($field) {
                'top_margin_mm', 'bottom_margin_mm' => (int) $state[$field],
                'block_print_when_due' => (bool) $state[$field],
                default => filled($state[$field]) ? $state[$field] : null,
            };
            $old = $settings->get($name, $default);

            if ($new !== $old) {
                $before[$name] = $old;
                $after[$name] = $new;
            }
        }

        if ($after !== []) {
            DB::connection('clinic')->transaction(function () use ($settings, $audit, $before, $after): void {
                foreach ($after as $name => $value) {
                    $settings->set($name, $value);
                }

                $audit->record('settings.updated', null, $before, $after);
            });
        }

        Notification::make()->success()->title('Report layout saved')->send();
    }
}
