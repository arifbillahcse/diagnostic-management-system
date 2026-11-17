<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
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
            'delivery_days' => (int) $settings->get(Permissions::SETTING_DELIVERY_DAYS, 1),
            'delivery_time' => (string) $settings->get(Permissions::SETTING_DELIVERY_TIME, '19:00'),
            'after_save' => (string) $settings->get(Permissions::SETTING_AFTER_SAVE, 'ask'),
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
            Section::make('After a bill is saved')
                ->description('What the billing screen does once the bill is in. The invoice can always be printed again from the bill or the order list.')
                ->schema([
                    Radio::make('after_save')
                        ->hiddenLabel()
                        ->options([
                            'ask' => 'Show print options: invoice (patient and office copy), sample labels',
                            'invoice' => 'Print the invoice (patient and office copy) at once',
                            'none' => 'Do nothing; I print from the strip at the top',
                        ])
                        ->required(),
                ]),
            Section::make('Report delivery')
                ->description('Printed on the invoice as the day the report is ready. Staff can choose another time on any single order.')
                ->columns(2)
                ->schema([
                    TextInput::make('delivery_days')
                        ->label('Days after the order')
                        ->numeric()->integer()->minValue(0)->maxValue(30)->required()
                        ->helperText('0 means the same day, 1 the next day.'),
                    TextInput::make('delivery_time')
                        ->label('Time of day')
                        ->type('time')->required()
                        ->rule('regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'),
                ]),
        ]);
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $state = $this->form->getState();
        $new = [
            Permissions::SETTING_REFUND_APPROVAL => (bool) $state['refund_requires_approval'],
            Permissions::SETTING_DELIVERY_DAYS => (int) $state['delivery_days'],
            Permissions::SETTING_DELIVERY_TIME => (string) $state['delivery_time'],
            Permissions::SETTING_AFTER_SAVE => in_array($state['after_save'], ['ask', 'invoice', 'none'], true) ? $state['after_save'] : 'ask',
        ];
        $defaults = [
            Permissions::SETTING_REFUND_APPROVAL => true,
            Permissions::SETTING_DELIVERY_DAYS => 1,
            Permissions::SETTING_DELIVERY_TIME => '19:00',
            Permissions::SETTING_AFTER_SAVE => 'ask',
        ];

        DB::connection('clinic')->transaction(function () use ($settings, $audit, $new, $defaults): void {
            foreach ($new as $key => $value) {
                $old = $settings->get($key, $defaults[$key]);

                if ($old !== $value) {
                    $settings->set($key, $value);
                    $audit->record('settings.updated', null, [$key => $old], [$key => $value]);
                }
            }
        });

        Notification::make()->success()->title('Settings saved')->send();
    }
}
