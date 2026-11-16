<?php

declare(strict_types=1);

namespace Modules\Laboratory\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Support\WorkPlanner;

final class SyncWorkCommand extends Command
{
    protected $signature = 'laboratory:sync-work
        {--days=3 : How many days back to look}
        {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Create any laboratory work that is missing for recent orders, for example for a test whose lab set-up was added after it was ordered';

    public function handle(ClinicManager $manager): int
    {
        $created = 0;

        $manager->eachClinic(function () use (&$created): void {
            if (! app(ModuleState::class)->isEnabled('laboratory')) {
                return;
            }

            Order::query()
                ->where('ordered_at', '>=', now()->subDays((int) $this->option('days')))
                ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::InProgress->value])
                ->chunkById(200, function ($orders) use (&$created): void {
                    foreach ($orders as $order) {
                        $created += app(WorkPlanner::class)->planFor($order);
                    }
                });
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$created} missing work item(s) created.");

        return self::SUCCESS;
    }
}
