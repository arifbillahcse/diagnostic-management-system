<?php

declare(strict_types=1);

namespace Modules\Laboratory;

use App\Core\Modules\Module;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderItemCancelling;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Laboratory\Console\SyncWorkCommand;
use Modules\Laboratory\Listeners\CancelWork;
use Modules\Laboratory\Listeners\GuardItemCancellation;
use Modules\Laboratory\Listeners\PlanWorkForAddedItem;
use Modules\Laboratory\Listeners\PlanWorkForOrder;

final class LaboratoryModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        $bench = [
            Permissions::WORK_VIEW,
            Permissions::SAMPLES_COLLECT,
            Permissions::SAMPLES_RECEIVE,
            Permissions::SAMPLES_REJECT,
            Permissions::RESULTS_ENTER,
        ];

        return [
            // Verifying and amending are clinical responsibilities, so the
            // admin role does not get them by default.
            'admin' => [...$bench, Permissions::CATALOG_MANAGE, Permissions::SETTINGS_MANAGE],
            'lab_technician' => $bench,
            'verifier' => [...$bench, Permissions::RESULTS_VERIFY, Permissions::RESULTS_AMEND, Permissions::CATALOG_MANAGE],
            'receptionist' => [Permissions::WORK_VIEW, Permissions::SAMPLES_COLLECT],
        ];
    }

    public function exports(): array
    {
        return [
            'lab_sample_types' => 'Sample types',
            'lab_tests' => 'Lab tests',
            'lab_analytes' => 'Analytes',
            'lab_test_analytes' => 'Values per test',
            'lab_reference_ranges' => 'Reference ranges',
            'lab_samples' => 'Samples',
            'lab_work_items' => 'Tests performed',
            'lab_results' => 'Results',
            'lab_result_versions' => 'Verified result versions',
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

    public function widgets(): array
    {
        return class_exists(Filament\LabStatusWidget::class) ? [Filament\LabStatusWidget::class] : [];
    }

    public function boot(): void
    {
        View::addNamespace('laboratory', $this->manifest->path.'/resources/views');

        Event::listen(OrderPlaced::class, [PlanWorkForOrder::class, 'handle']);
        Event::listen(OrderItemAdded::class, [PlanWorkForAddedItem::class, 'handle']);
        Event::listen(OrderItemCancelling::class, [GuardItemCancellation::class, 'handle']);
        Event::listen(OrderItemCancelled::class, [CancelWork::class, 'handle']);
        Event::listen(OrderCancelled::class, [CancelWork::class, 'handle']);

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([SyncWorkCommand::class]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('laboratory:sync-work')->hourly();
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:laboratory'])->group($routes);
        }
    }
}
