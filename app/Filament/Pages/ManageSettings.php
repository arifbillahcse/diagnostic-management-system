<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Auth\CorePermissions;
use App\Core\Settings\Actions\UpdateSettings;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
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
        $this->form->fill(array_map(fn (string $name) => $settings->get($name), self::FIELDS));
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
                    ]),
            ]);
    }

    public function save(UpdateSettings $update): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (self::FIELDS as $field => $name) {
            $values[$name] = filled($state[$field] ?? null) ? $state[$field] : null;
        }

        $update->handle($values);

        Notification::make()->success()->title('Settings saved')->send();
    }
}
