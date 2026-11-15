<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Console\Command;

final class ModulesValidate extends Command
{
    protected $signature = 'modules:validate';

    protected $description = 'Check every module manifest, dependency, and entry class. Fails the build on any problem';

    public function handle(ModuleRegistry $registry): int
    {
        $errors = [...$registry->errors(), ...$registry->entryErrors()];

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info(count($registry->all()).' module(s) valid. Load order: '.(implode(', ', array_keys($registry->all())) ?: '(none)'));

        return self::SUCCESS;
    }
}
