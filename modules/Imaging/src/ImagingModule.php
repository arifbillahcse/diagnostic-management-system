<?php

declare(strict_types=1);

namespace Modules\Imaging;

use App\Core\Modules\Module;
use App\Core\Modules\ModuleState;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\OrderItemCancelling;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Support\OrderReports;
use Modules\Imaging\Listeners\CancelStudies;
use Modules\Imaging\Listeners\GuardCancellation;
use Modules\Imaging\Listeners\PlanStudies;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Support\LabReports;

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

        // Each imaging report's row on the order page, and "all reports" when lab results are ready too.
        app(OrderReports::class)->add(function (Order $order): array {
            if (! app(ModuleState::class)->isEnabled('imaging') || ! Gate::allows(Permissions::REPORTS_PRINT, $order->branch_id)) {
                return [];
            }

            $studies = Study::where('order_id', $order->id)->where('status', '!=', 'cancelled')->orderBy('id')->get();
            $rows = $studies->map(fn (Study $study): array => [
                'label' => $study->study_name.($study->version > 1 && $study->status === 'final' ? " (version {$study->version})" : ''),
                'status' => Enums::status($study->status),
                'color' => Enums::color($study->status),
                'url' => $study->status === 'final' ? route('imaging.print', ['order' => $order->public_id, 'study' => $study->public_id]) : null,
                'order' => 20,
            ])->all();

            $lab = app(LabReports::class);

            if ($studies->contains(fn (Study $s): bool => $s->status === 'final') && $lab->mayPrintLab($order) && $lab->hasReleased($order)) {
                $rows[] = ['label' => 'All ready reports in one file (lab and imaging)', 'status' => 'Ready', 'color' => 'success', 'url' => route('imaging.print.all', $order->public_id), 'order' => 90];
            }

            return $rows;
        });

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([Console\SyncStudiesCommand::class]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('imaging:sync-studies')->hourlyAt(25);
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:imaging'])->group($routes);
        }
    }
}
