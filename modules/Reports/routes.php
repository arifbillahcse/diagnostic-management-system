<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\ReportController;

/*
| Loaded by ReportsModule::boot() inside the "web", "auth", and
| "module:reports" middleware.
*/

Route::get('/print/report/{order:public_id}', [ReportController::class, 'pdf'])->name('reports.print');
