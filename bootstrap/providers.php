<?php

use App\Core\CoreServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    // First, so its domain-bound routes win over the clinic panel's on the console domain.
    PlatformPanelProvider::class,
    AdminPanelProvider::class,
];
