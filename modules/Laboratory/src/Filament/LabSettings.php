<?php

declare(strict_types=1);

namespace Modules\Laboratory\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Permissions;
use UnitEnum;

class LabSettings extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Lab set-up';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Laboratory settings';

    protected static ?string $title = 'Laboratory settings';

    protected static ?string $slug = 'lab-setup/settings';

    protected string $view = 'laboratory::settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function moduleKey(): string
    {
        return 'laboratory';
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
        $this->form->fill([
            'verifier_must_differ' => (bool) $settings->get(Permissions::SETTING_VERIFIER_MUST_DIFFER, true),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Verification')->schema([
                Toggle::make('verifier_must_differ')
                    ->label('Results must be verified by a different person from the one who entered them')
                    ->helperText('Recommended: a second pair of eyes catches typing mistakes. Switch off only if one qualified person both enters and verifies.'),
            ]),
        ]);
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $new = (bool) $this->form->getState()['verifier_must_differ'];
        $old = (bool) $settings->get(Permissions::SETTING_VERIFIER_MUST_DIFFER, true);

        if ($new !== $old) {
            DB::connection('clinic')->transaction(function () use ($settings, $audit, $old, $new): void {
                $settings->set(Permissions::SETTING_VERIFIER_MUST_DIFFER, $new);
                $audit->record('settings.updated', null, [Permissions::SETTING_VERIFIER_MUST_DIFFER => $old], [Permissions::SETTING_VERIFIER_MUST_DIFFER => $new]);
            });
        }

        Notification::make()->success()->title('Settings saved')->send();
    }
}
