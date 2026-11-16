<?php

use App\Core\Export\ExportController;
use Illuminate\Support\Facades\Route;

// Screens are served by the Filament panel
// (app/Providers/Filament/AdminPanelProvider.php).
// Module routes are registered by each module's entry class.

Route::middleware('auth')->group(function (): void {
    Route::get('/export/all.zip', [ExportController::class, 'all'])->name('export.all');
    Route::get('/export/{table}.csv', [ExportController::class, 'table'])->where('table', '[a-z_]+')->name('export.table');
});
