<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Laboratory\Http\LabelController;
use Modules\Laboratory\Http\TransferController;

/*
| Loaded by LaboratoryModule::boot() inside the "web", "auth", and
| "module:laboratory" middleware.
*/

Route::get('/print/sample-label/{sample:public_id}', [LabelController::class, 'show'])->name('laboratory.print.label');
Route::get('/print/order-labels/{order:public_id}', [LabelController::class, 'forOrder'])->name('laboratory.print.order-labels');
Route::get('/print/transfer/{transfer:public_id}', TransferController::class)->name('laboratory.print.transfer');
