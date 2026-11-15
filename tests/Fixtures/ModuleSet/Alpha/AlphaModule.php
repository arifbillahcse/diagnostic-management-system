<?php

declare(strict_types=1);

namespace Tests\Fixtures\ModuleSet\Alpha;

use App\Core\Modules\Module;

final class AlphaModule extends Module
{
    public function permissions(): array
    {
        return ['alpha.things.view' => 'View things'];
    }

    public function defaultGrants(): array
    {
        return [];
    }
}
