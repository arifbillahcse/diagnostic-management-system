<?php

declare(strict_types=1);

namespace Modules\Imaging\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Support\StudyPlanner;

final class SyncStudiesCommand extends Command
{
    protected $signature = 'imaging:sync-studies
        {--days=3 : How many days back to look}
        {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Add imaging studies missing from recent orders, for example orders billed before the imaging module was switched on';

    public function handle(ClinicManager $manager): int
    {
        $created = 0;

        $manager->eachClinic(function () use (&$created): void {
            if (! app(ModuleState::class)->isEnabled('imaging')) {
                return;
            }

            Order::query()
                ->where('ordered_at', '>=', now()->subDays((int) $this->option('days')))
                ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::InProgress->value])
                ->chunkById(200, function ($orders) use (&$created): void {
                    foreach ($orders as $order) {
                        $created += app(StudyPlanner::class)->planFor($order);
                    }
                });
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$created} missing imaging stud(ies) added.");

        return self::SUCCESS;
    }
}
