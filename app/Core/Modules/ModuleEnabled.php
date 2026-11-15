<?php

declare(strict_types=1);

namespace App\Core\Modules;

final class ModuleEnabled
{
    public function __construct(public readonly string $key) {}
}
