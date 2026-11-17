<?php

declare(strict_types=1);

namespace Modules\Reports;

use App\Core\Modules\Module;
use App\Core\Modules\ModuleState;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Support\OrderReports;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;

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

        // The lab report's row on the order page.
        app(OrderReports::class)->add(function (Order $order): array {
            if (! app(ModuleState::class)->isEnabled('reports')) {
                return [];
            }

            $work = WorkItem::where('order_id', $order->id)->where('status', '!=', WorkStatus::Cancelled->value)->get(['status'])->pluck('status');

            if ($work->isEmpty()) {
                return [];
            }

            $ready = $work->filter(fn ($status): bool => $status === WorkStatus::Released)->count();
            $total = $work->count();

            return [[
                'label' => "Lab report ({$ready} of {$total} tests ready)",
                'status' => $ready === $total ? 'Ready' : ($ready > 0 ? 'Partly ready' : 'Waiting'),
                'color' => $ready === $total ? 'success' : ($ready > 0 ? 'info' : 'warning'),
                'url' => $ready > 0 && Gate::allows(Permissions::REPORTS_PRINT, $order->branch_id) ? route('reports.print', $order->public_id) : null,
                'order' => 10,
            ]];
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            Route::middleware(['web', 'auth', 'module:reports'])->group($routes);
        }
    }
}
