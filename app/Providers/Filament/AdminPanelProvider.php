<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Core\Http\SecurityHeaders;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\Http\EnsureSessionBelongsToClinic;
use App\Core\Tenancy\Http\ResolveClinic;
use App\Filament\Pages\MyAccount;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff panel every clinic uses, served at the root of its address.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        [$resources, $pages, $widgets] = $this->moduleScreens();

        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->profile(MyAccount::class, isSimple: false)
            // Optional two-step sign-in with an authenticator app, set up by
            // each user on their own account page.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->brandName(fn (): string => $this->clinicName())
            ->colors([
                // Brand green. Filament fills buttons with shade 400, so that
                // shade is dark enough for white text.
                'primary' => [
                    50 => '#F0F9F6', 100 => '#DDF3EA', 200 => '#BCE5D6', 300 => '#57B397',
                    400 => '#1A7F66', 500 => '#17735D', 600 => '#145F4E', 700 => '#124C40',
                    800 => '#14403A', 900 => '#0E2E29', 950 => '#0A2621',
                ],
            ])
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(['Front desk', 'Laboratory', 'Finance', 'Sales reports', 'Messaging', 'Catalog', 'Lab set-up', 'Administration'])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.theme'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->resources($resources)
            ->pages([Dashboard::class, ...$pages])
            ->widgets([AccountWidget::class, ...$widgets])
            ->middleware([
                SecurityHeaders::class,
                ResolveClinic::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnsureSessionBelongsToClinic::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Screens contributed by modules.
     *
     * All clinics share this code, so every module's screens are registered
     * here. Each screen then hides and blocks itself for a clinic that has
     * the module switched off (see App\Core\Modules\GatesModuleAccess).
     *
     * @return array{0: list<class-string>, 1: list<class-string>, 2: list<class-string>}
     */
    private function moduleScreens(): array
    {
        $registry = app(ModuleRegistry::class);
        $resources = $pages = $widgets = [];

        foreach (array_keys($registry->all()) as $key) {
            if (! class_exists($registry->get($key)->entry)) {
                continue;
            }

            $module = $registry->module($key);
            $resources = [...$resources, ...$module->resources()];
            $pages = [...$pages, ...$module->pages()];
            $widgets = [...$widgets, ...$module->widgets()];
        }

        return [$resources, $pages, $widgets];
    }

    private function clinicName(): string
    {
        if (! app(ClinicContext::class)->has()) {
            return (string) config('app.name');
        }

        return (string) app(Settings::class)->get('clinic.name', app(ClinicContext::class)->get()->name);
    }
}
