<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Messaging\Http\ReportLinkController;

/*
| Loaded by MessagingModule::boot() inside the "web" and "module:messaging"
| middleware, WITHOUT "auth": the patient opens this from an SMS. The number
| of tries per minute is limited so links cannot be guessed.
*/

Route::get('/r/{token}', [ReportLinkController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('messaging.report-link');
