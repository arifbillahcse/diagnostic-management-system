<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Runs clinic migrations: the core tables, then the tables of every module
 * the clinic has installed.
 */
final class ClinicMigrator
{
    public function __construct(
        private readonly ClinicManager $manager,
        private readonly ModuleRegistry $registry,
        private readonly ModuleState $state,
    ) {}

    public function migrate(Clinic $clinic): void
    {
        $this->manager->run($clinic, function (): void {
            $this->runPath(database_path('migrations/clinic'));

            foreach (array_keys($this->registry->all()) as $key) {
                if ($this->state->isInstalled($key)) {
                    $this->runPath($this->registry->get($key)->migrationsPath());
                }
            }
        });
    }

    /** Run the migrations in one folder against the active clinic's database. */
    public function runPath(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $exit = Artisan::call('migrate', [
            '--database' => 'clinic',
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException("Migrations in [{$path}] failed: ".Artisan::output());
        }
    }
}
