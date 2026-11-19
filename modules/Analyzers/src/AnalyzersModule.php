<?php

declare(strict_types=1);

namespace Modules\Analyzers;

use App\Core\Modules\Module;
use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

final class AnalyzersModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'lab_technician' => [Permissions::INBOX_RESOLVE],
            'verifier' => [Permissions::INBOX_RESOLVE],
        ];
    }

    public function exports(): array
    {
        return [
            'anz_machines' => 'Lab machines (without keys)',
            'anz_mappings' => 'Lab machine test codes',
            'anz_messages' => 'Lab machine messages',
            'anz_results' => 'Lab machine results',
        ];
    }

    public function resources(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::resources() : [];
    }

    public function boot(): void
    {
        View::addNamespace('analyzers', $this->manifest->path.'/resources/views');

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([Console\ReplayCommand::class, Console\SampleDataCommand::class]));

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::group([], $routes);
        }
    }
}
