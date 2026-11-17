<?php

declare(strict_types=1);

use App\Core\Tenancy\Http\ResolveClinic;
use Illuminate\Support\Facades\Route;
use Modules\Analyzers\Http\ReceiveController;

/*
| The bridge program's address. Not in the "web" group: no session, no
| cookies, no CSRF. The clinic is found from the domain as for every
| request, the module must be on, and the machine's key is checked by the
| controller. Tries are limited per minute.
*/

Route::post('/api/analyzers/messages', ReceiveController::class)
    ->middleware([ResolveClinic::class, 'module:analyzers', 'throttle:120,1'])
    ->name('analyzers.receive');
