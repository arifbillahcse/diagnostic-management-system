<?php

declare(strict_types=1);

namespace Tests\Fixtures\ModuleSet\Gamma;

use App\Core\Modules\Module;

final class GammaModule extends Module
{
    public function permissions(): array
    {
        return [];
    }

    public function defaultGrants(): array
    {
        return [];
    }
}
