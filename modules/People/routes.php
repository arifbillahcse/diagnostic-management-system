<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\People\Http\PayslipController;

/*
| Loaded by PeopleModule::boot() inside the "web", "auth", and
| "module:people" middleware. Addresses use the random public ID.
*/

Route::get('/print/payslip/{line:public_id}', [PayslipController::class, 'one'])->name('people.print.payslip');
Route::get('/print/payslips/{run:public_id}', [PayslipController::class, 'run'])->name('people.print.payslips');
