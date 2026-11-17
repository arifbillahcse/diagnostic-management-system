<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Portal\Http\PortalController;

/*
| Loaded by PortalModule::boot() inside the "web" and "module:portal"
| middleware, WITHOUT "auth": patients open these pages. Looking up a bill
| is limited per minute and per bill number so numbers cannot be guessed.
*/

Route::get('/reports', [PortalController::class, 'form'])->name('portal.form');
Route::post('/reports', [PortalController::class, 'lookup'])->middleware('throttle:10,1')->name('portal.lookup');
Route::post('/reports/leave', [PortalController::class, 'leave'])->name('portal.leave');
Route::get('/reports/{order:public_id}', [PortalController::class, 'show'])->middleware('throttle:60,1')->name('portal.show');
Route::get('/reports/{order:public_id}/{kind}', [PortalController::class, 'download'])
    ->whereIn('kind', ['lab', 'imaging', 'all'])->middleware('throttle:30,1')->name('portal.download');
