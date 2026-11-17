<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\PurchasePrintController;

/*
| Loaded by InventoryModule::boot() inside the "web", "auth", and
| "module:inventory" middleware. Addresses use the random public ID.
*/

Route::get('/print/purchase/{purchase:public_id}', PurchasePrintController::class)->name('inventory.print.purchase');
