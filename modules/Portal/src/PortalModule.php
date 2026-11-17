<?php

declare(strict_types=1);

namespace Modules\Portal;

use App\Core\Modules\Module;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Support\InvoiceNotes;

final class PortalModule extends Module
{
    public function boot(): void
    {
        View::addNamespace('portal', $this->manifest->path.'/resources/views');

        // Tell the patient on the bill where to see their reports.
        app(InvoiceNotes::class)->add(function (Invoice $invoice): ?string {
            if (! app(ModuleState::class)->isEnabled('portal')) {
                return null;
            }

            $domain = app(ClinicContext::class)->require()->domains()->orderByDesc('is_primary')->orderBy('id')->value('domain');

            return $domain ? "See your reports online: {$domain}/reports (bill number and mobile number)" : null;
        });

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            // No "auth": patients use this page. Tries are limited per minute.
            Route::middleware(['web', 'module:portal'])->group($routes);
        }
    }
}
