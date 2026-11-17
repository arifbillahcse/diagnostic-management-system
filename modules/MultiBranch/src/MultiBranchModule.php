<?php

declare(strict_types=1);

namespace Modules\MultiBranch;

use App\Core\Branches\CurrentBranch;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleState;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

final class MultiBranchModule extends Module
{
    public function boot(): void
    {
        View::addNamespace('multibranch', $this->manifest->path.'/resources/views');

        // Core asks this on every request; it is never true while the module is off.
        app(CurrentBranch::class)->useSwitching(fn (): bool => app(ModuleState::class)->isEnabled('multibranch'));

        FilamentView::registerRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, function (): string {
            $branches = app(CurrentBranch::class);

            return $branches->multi()
                ? view('multibranch::switcher', ['branches' => $branches->allowed(), 'current' => $branches->get()])->render()
                : '';
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:multibranch'])->group($routes);
        }
    }
}
