<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Core\Http\SecurityHeaders;
use App\Core\Tenancy\Http\ResolveClinic;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform console: Softorio's own admin area for every clinic. It
 * answers only on PLATFORM_DOMAIN, signs in platform users (never clinic
 * users), requires two-step sign-in, and uses only the control database.
 * Without PLATFORM_DOMAIN it is not registered at all.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (blank(config('dms.platform.domain'))) {
            return;
        }

        parent::register();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->domain((string) config('dms.platform.domain'))
            ->path('')
            ->login()
            ->authGuard('platform')
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->brandName('Softorio platform')
            ->colors(['primary' => [
                50 => '#EEF2FF', 100 => '#E0E7FF', 200 => '#C7D2FE', 300 => '#A5B4FC', 400 => '#4F46E5', 500 => '#4338CA',
                600 => '#3730A3', 700 => '#312E81', 800 => '#282570', 900 => '#1E1B4B', 950 => '#14123A',
            ]])
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.theme').view('platform.theme'))
            ->discoverResources(in: app_path('Platform/Filament/Resources'), for: 'App\Platform\Filament\Resources')
            ->discoverPages(in: app_path('Platform/Filament/Pages'), for: 'App\Platform\Filament\Pages')
            ->pages([Dashboard::class])
            ->middleware([
                SecurityHeaders::class,
                ResolveClinic::class, // leaves the console domain without a clinic
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
