<?php

declare(strict_types=1);

namespace Modules\Imaging;

use App\Core\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderItemCancelling;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Imaging\Listeners\CancelStudies;
use Modules\Imaging\Listeners\GuardCancellation;
use Modules\Imaging\Listeners\PlanStudies;

final class ImagingModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            // The doctor role: pathologists and sonologists both sign reports.
            'verifier' => [Permissions::REPORTS_WRITE, Permissions::REPORTS_SIGN, Permissions::REPORTS_PRINT],
            'receptionist' => [Permissions::REPORTS_PRINT],
            'accountant' => [Permissions::REPORTS_PRINT],
        ];
    }

    public function appendOnlyTables(): array
    {
        return ['img_report_versions'];
    }

    public function exports(): array
    {
        return [
            'img_templates' => 'Imaging report templates',
            'img_phrases' => 'Imaging phrases',
            'img_studies' => 'Imaging studies',
            'img_report_versions' => 'Imaging report versions',
        ];
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
        View::addNamespace('imaging', $this->manifest->path.'/resources/views');

        Event::listen(OrderPlaced::class, [PlanStudies::class, 'handle']);
        Event::listen(OrderItemAdded::class, [PlanStudies::class, 'handle']);
        Event::listen(OrderItemCancelling::class, [GuardCancellation::class, 'handle']);
        Event::listen(OrderItemCancelled::class, [CancelStudies::class, 'handle']);
        Event::listen(OrderCancelled::class, [CancelStudies::class, 'handle']);

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:imaging'])->group($routes);
        }
    }
}
