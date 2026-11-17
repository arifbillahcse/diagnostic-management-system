<?php

declare(strict_types=1);

namespace Modules\Reports;

use App\Core\Modules\Module;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

final class ReportsModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        $print = [Permissions::REPORTS_VIEW, Permissions::REPORTS_PRINT];

        return [
            'admin' => array_keys(Permissions::all()),
            'receptionist' => $print,
            'lab_technician' => $print,
            'verifier' => $print,
            'accountant' => [...$print, Permissions::REPORTS_PRINT_WITH_DUE],
        ];
    }

    public function exports(): array
    {
        return [];
    }

    public function resources(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::resources() : [];
    }

    public function pages(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::pages() : [];
    }

    public function boot(): void
    {
        View::addNamespace('reports', $this->manifest->path.'/resources/views');

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:reports'])->group($routes);
        }
    }
}
