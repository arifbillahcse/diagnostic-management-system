<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Documents\ClinicFiles;
use App\Core\Documents\PageLayout;
use App\Core\Settings\Settings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
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
use UnitEnum;

/** Page set-up of every printed report: paper, banners, footer, watermark. */
class ReportLayout extends Page
{
    /** Form field => [setting name, default]. */
    private const FIELDS = [
        'letterhead' => [PageLayout::SETTING_LETTERHEAD, 'print'],
        'top_margin_mm' => [PageLayout::SETTING_TOP_MARGIN, 40],
        'bottom_margin_mm' => [PageLayout::SETTING_BOTTOM_MARGIN, 25],
        'header_image' => [PageLayout::SETTING_HEADER_IMAGE, null],
        'footer_image' => [PageLayout::SETTING_FOOTER_IMAGE, null],
        'watermark' => [PageLayout::SETTING_WATERMARK, false],
        'footer_text' => [PageLayout::SETTING_FOOTER, null],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 45;

    protected static ?string $navigationLabel = 'Report layout';

    protected static ?string $title = 'Report layout';

    protected static ?string $slug = 'report-layout';

    protected string $view = 'filament.pages.report-layout';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows(CorePermissions::SETTINGS_MANAGE);
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
                    ->placeholder('Results relate only to the sample tested.')
                    ->helperText('Printed at the foot of lab and imaging reports.'),
            ]),
            Section::make('Banner images')
                ->description('A coloured header and footer across the whole page, as on printed letterhead. Only used on plain paper; without them the clinic name and address are typed.')
                ->visible(fn (Get $get): bool => $get('letterhead') !== 'preprinted')
                ->schema([
                    $this->banner('header_image', 'Header banner', PageLayout::HEADER_IMAGE_MAX_KB, 'about 1240 pixels wide and 250 tall'),
                    $this->banner('footer_image', 'Footer banner', PageLayout::FOOTER_IMAGE_MAX_KB, 'about 1240 pixels wide and 100 tall'),
                    Toggle::make('watermark')
                        ->label('Print a faint copy of the logo behind the results')
                        ->helperText('Uses the logo from Clinic settings.'),
                ]),
        ]);
    }

    private function banner(string $field, string $label, int $maxKb, string $size): FileUpload
    {
        return FileUpload::make($field)
            ->label($label)
            ->disk(ClinicFiles::DISK)
            ->directory(fn (): string => app(ClinicFiles::class)->directory('branding'))
            ->visibility('private')
            ->acceptedFileTypes(['image/png', 'image/jpeg'])
            ->maxSize($maxKb)
            ->previewable(false)
            ->downloadable(false)
            ->openable(false)
            ->helperText("PNG or JPEG, up to {$maxKb} KB, {$size}. A small file keeps reports quick to create; save photos as JPEG.");
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        Gate::authorize(CorePermissions::SETTINGS_MANAGE);

        $state = $this->form->getState();
        $before = $after = [];

        foreach (self::FIELDS as $field => [$name, $default]) {
            if (! array_key_exists($field, $state)) {
                continue; // hidden fields keep their stored value
            }

            $new = match ($field) {
                'top_margin_mm', 'bottom_margin_mm' => (int) $state[$field],
                'watermark' => (bool) $state[$field],
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
