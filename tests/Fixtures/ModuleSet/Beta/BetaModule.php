<?php

declare(strict_types=1);

namespace Tests\Fixtures\ModuleSet\Beta;

use App\Core\Modules\Module;

final class BetaModule extends Module
{
    public function permissions(): array
    {
        return ['beta.items.manage' => 'Manage items'];
    }

    public function defaultGrants(): array
    {
        return ['admin' => ['beta.items.manage']];
    }
}
