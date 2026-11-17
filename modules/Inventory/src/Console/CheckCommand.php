<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\Inventory\Support\StockLedger;

final class CheckCommand extends Command
{
    protected $signature = 'inventory:check {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Check that each stock item\'s stored quantity and value equal the sum of its movements, and correct any that do not. Scheduled nightly';

    public function handle(ClinicManager $manager): int
    {
        $corrected = 0;

        $manager->eachClinic(function () use (&$corrected): void {
            if (! app(ModuleState::class)->isEnabled('inventory')) {
                return;
            }

            foreach (app(StockLedger::class)->reconcile() as $name => $figures) {
                $corrected++;
                $this->warn("{$name}: stored {$figures['stored']}, movements {$figures['movements']}. Corrected to the movements.");
            }
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$corrected} item(s) corrected.");

        return self::SUCCESS;
    }
}
