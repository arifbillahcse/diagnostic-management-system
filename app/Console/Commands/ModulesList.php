<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Console\Command;

final class ModulesList extends Command
{
    protected $signature = 'modules:list';

    protected $description = 'List the modules present in the code base, in load order';

    public function handle(ModuleRegistry $registry): int
    {
        $this->table(
            ['Key', 'Name', 'Version', 'Requires', 'Prefix', 'On by default'],
            array_map(fn (ModuleManifest $m): array => [
                $m->key,
                $m->name,
                $m->version,
                implode(', ', $m->requires) ?: '-',
                $m->tablePrefix,
                $m->defaultEnabled ? 'yes' : 'no',
            ], array_values($registry->all())),
        );

        foreach ($registry->errors() as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
