<?php

declare(strict_types=1);

namespace App\Core\Modules\Actions;

use App\Core\Auth\CorePermissions;
use App\Core\Modules\ModuleManager;
use Illuminate\Support\Facades\Gate;

final class EnableModule
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(string $key): void
    {
        Gate::authorize(CorePermissions::MODULES_MANAGE);

        $this->modules->enable($key);
    }
}
