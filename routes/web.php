<?php

use App\Core\Export\ExportController;
use App\Platform\Billing\InvoicePrintController;
use Illuminate\Support\Facades\Route;

// Screens are served by the Filament panel
// (app/Providers/Filament/AdminPanelProvider.php).
// Module routes are registered by each module's entry class.

Route::middleware('auth')->group(function (): void {
    Route::get('/export/all.zip', [ExportController::class, 'all'])->name('export.all');
    Route::get('/export/{table}.csv', [ExportController::class, 'table'])->where('table', '[a-z_]+')->name('export.table');
});

// Subscription invoices: on the platform console's address for platform
// users, and on a clinic's own address for its owner (own invoices only).
if (filled(config('dms.platform.domain'))) {
    Route::domain((string) config('dms.platform.domain'))->middleware(['web', 'auth:platform'])
        ->get('/billing/invoices/{invoice:public_id}', InvoicePrintController::class)->name('platform.invoice.print');
}

Route::middleware(['web', 'auth'])
    ->get('/subscription/invoices/{invoice:public_id}', InvoicePrintController::class)->name('subscription.invoice.print');
