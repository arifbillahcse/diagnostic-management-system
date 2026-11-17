<?php

declare(strict_types=1);

namespace Modules\Reports\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
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
        'block_print_when_due' => [Permissions::SETTING_BLOCK_WHEN_DUE, false],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Lab report printing';

    protected static ?string $title = 'Lab report printing';

    protected static ?string $slug = 'lab-setup/report-printing';

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
        return $schema->statePath('data')->components([
            Section::make('Paper and signatures')->schema([
                Placeholder::make('moved')->hiddenLabel()
                    ->content('The paper, banners, footer and watermark are set in Administration, Report layout. Signatures are in Administration, Report signatures. Both are shared with imaging reports.'),
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

        Notification::make()->success()->title('Settings saved')->send();
    }
}
