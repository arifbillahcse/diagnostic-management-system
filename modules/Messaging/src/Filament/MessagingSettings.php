<?php

declare(strict_types=1);

namespace Modules\Messaging\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\GatesModuleAccess;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Messaging\Actions\ManageMessages;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\MessagingException;
use Modules\Messaging\Support\Messenger;
use UnitEnum;

class MessagingSettings extends Page
{
    use GatesModuleAccess;

    /** Form field => [setting name, default, type]. */
    private const FIELDS = [
        'sms_enabled' => [Permissions::SETTING_SMS_ENABLED, false, 'bool'],
        'email_enabled' => [Permissions::SETTING_EMAIL_ENABLED, false, 'bool'],
        'notify_order' => [Permissions::SETTING_NOTIFY_ORDER, true, 'bool'],
        'notify_report' => [Permissions::SETTING_NOTIFY_REPORT, true, 'bool'],
        'report_link' => [Permissions::SETTING_REPORT_LINK, true, 'bool'],
        'link_days' => [Permissions::SETTING_LINK_DAYS, 14, 'int'],
        'due_reminder_days' => [Permissions::SETTING_DUE_REMINDER_DAYS, 0, 'int'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Messaging';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Messaging settings';

    protected static ?string $title = 'Messaging settings';

    protected static ?string $slug = 'messaging/settings';

    protected string $view = 'messaging::settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function moduleKey(): string
    {
        return 'messaging';
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

    /** What the installation's SMS gateway is doing, in plain words. */
    public function getGatewayNoteProperty(): string
    {
        return match (app(Messenger::class)->smsDriver()?->name()) {
            'test' => 'SMS gateway: test mode. Messages are written to the message log as sent, but nothing reaches a phone. Your provider switches real sending on.',
            'http' => 'SMS gateway: connected. Messages are sent to phones and are charged by the part.',
            default => 'SMS gateway: switched off for this installation. Email can still be used.',
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Channels')->columns(2)->schema([
                Toggle::make('sms_enabled')->label('Send SMS'),
                Toggle::make('email_enabled')->label('Send email')->helperText('Only to patients with an email address on their record.'),
            ]),
            Section::make('What to send')->columns(2)->schema([
                Toggle::make('notify_order')->label('Bill confirmation, when an order is placed'),
                Toggle::make('notify_report')->label('Report ready, when the last result of an order is released'),
                Toggle::make('report_link')->label('Put a download link in the "report ready" message')
                    ->helperText('The patient opens their report without signing in. Anyone who has the link can open it, so it stops working after the number of days below.'),
                TextInput::make('link_days')->label('Days a download link works')->numeric()->integer()->minValue(1)->maxValue(90)->required(),
                TextInput::make('due_reminder_days')->label('Remind about an unpaid bill every (days)')->numeric()->integer()->minValue(0)->maxValue(60)->required()
                    ->helperText('0 switches reminders off. A bill is reminded about at most '.Permissions::DUE_REMINDER_LIMIT.' times.'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test SMS')
                ->color('gray')
                ->schema([TextInput::make('phone')->label('Mobile number')->tel()->required()->placeholder('01712345678')])
                ->action(function (array $data): void {
                    try {
                        app(ManageMessages::class)->sendTest($data['phone']);
                        Notification::make()->success()->title('Test message queued')->body('Look at the message log to see whether it was sent.')->send();
                    } catch (MessagingException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        $state = $this->form->getState();
        $before = $after = [];

        foreach (self::FIELDS as $field => [$name, $default, $type]) {
            $new = $type === 'bool' ? (bool) $state[$field] : (int) $state[$field];
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

        Notification::make()->success()->title('Messaging settings saved')->send();
    }
}
