<?php

declare(strict_types=1);

namespace Modules\Imaging\Filament;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Imaging\Permissions;

class ListStudies extends ListRecords
{
    protected static string $resource = StudyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')->label('Settings')->color('gray')
                ->visible(fn (): bool => Gate::allows(Permissions::TEMPLATES_MANAGE))
                ->fillForm(fn (): array => ['must_differ' => (bool) app(Settings::class)->get(Permissions::SETTING_SIGNER_MUST_DIFFER, false)])
                ->schema([
                    Toggle::make('must_differ')->label('A second doctor must sign')
                        ->helperText('Off: the doctor who writes a report can sign it. On: another doctor must sign it.'),
                ])
                ->action(function (array $data, Settings $settings, AuditLogger $audit): void {
                    Gate::authorize(Permissions::TEMPLATES_MANAGE);
                    $old = (bool) $settings->get(Permissions::SETTING_SIGNER_MUST_DIFFER, false);
                    $new = (bool) $data['must_differ'];

                    if ($old !== $new) {
                        DB::connection('clinic')->transaction(function () use ($settings, $audit, $old, $new): void {
                            $settings->set(Permissions::SETTING_SIGNER_MUST_DIFFER, $new);
                            $audit->record('settings.updated', null, [Permissions::SETTING_SIGNER_MUST_DIFFER => $old], [Permissions::SETTING_SIGNER_MUST_DIFFER => $new]);
                        });
                    }
                }),
        ];
    }
}
