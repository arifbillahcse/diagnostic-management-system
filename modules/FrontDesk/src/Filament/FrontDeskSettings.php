<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament;

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
use Modules\FrontDesk\Permissions;
use UnitEnum;

class FrontDeskSettings extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Front desk';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Front desk settings';

    protected static ?string $title = 'Front desk settings';

    protected static ?string $slug = 'front-desk/settings';

    protected string $view = 'frontdesk::settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function moduleKey(): string
    {
        return 'frontdesk';
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
            'refund_requires_approval' => (bool) $settings->get(Permissions::SETTING_REFUND_APPROVAL, true),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Refunds')->schema([
                Toggle::make('refund_requires_approval')
                    ->label('A refund must be approved by a second person')
                    ->helperText('Recommended. Switch off only if one person handles all the money, for example in a very small center.'),
            ]),
        ]);
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $new = (bool) $this->form->getState()['refund_requires_approval'];
        $old = (bool) $settings->get(Permissions::SETTING_REFUND_APPROVAL, true);

        if ($new !== $old) {
            DB::connection('clinic')->transaction(function () use ($settings, $audit, $old, $new): void {
                $settings->set(Permissions::SETTING_REFUND_APPROVAL, $new);
                $audit->record('settings.updated', null, [Permissions::SETTING_REFUND_APPROVAL => $old], [Permissions::SETTING_REFUND_APPROVAL => $new]);
            });
        }

        Notification::make()->success()->title('Settings saved')->send();
    }
}
