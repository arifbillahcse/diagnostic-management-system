<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FrontDesk\Http\PrintController;

/*
| Loaded by FrontDeskModule::boot() inside the "web", "auth", and
| "module:frontdesk" middleware. Addresses use the random public ID.
*/

Route::get('/print/invoice/{invoice:public_id}', [PrintController::class, 'invoice'])->name('frontdesk.print.invoice');
Route::get('/print/receipt/{payment:public_id}', [PrintController::class, 'receipt'])->name('frontdesk.print.receipt');
