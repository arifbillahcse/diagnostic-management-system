<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Imaging\Http\ImagingReportController;

/*
| Loaded by ImagingModule::boot() inside the "web", "auth", and
| "module:imaging" middleware. Addresses use the random public ID.
*/

Route::get('/print/imaging/{order:public_id}', [ImagingReportController::class, 'pdf'])->name('imaging.print');
