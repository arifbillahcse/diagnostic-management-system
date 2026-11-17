<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\MultiBranch\Http\SwitchBranchController;

/*
| Loaded by MultiBranchModule::boot() inside the "web", "auth", and
| "module:multibranch" middleware.
*/

Route::post('/branch', SwitchBranchController::class)->name('multibranch.switch');
