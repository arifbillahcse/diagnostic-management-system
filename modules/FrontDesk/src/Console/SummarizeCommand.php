<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Console;

use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\FrontDesk\Support\DailyFigures;

final class SummarizeCommand extends Command
{
    protected $signature = 'frontdesk:summarize
        {--date= : Local date to rebuild (YYYY-MM-DD)}
        {--days=8 : Without --date: how many days back from today to rebuild}
        {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Rebuild the daily front desk summaries used by dashboards and reports';

    public function handle(ClinicManager $manager): int
    {
        $rows = 0;

        $manager->eachClinic(function (Clinic $clinic) use (&$rows): void {
            if (! app(ModuleState::class)->isEnabled('frontdesk')) {
                return;
            }

            $figures = app(DailyFigures::class);
            $today = $figures->today();
            $dates = $this->option('date')
                ? [CarbonImmutable::parse($this->option('date'), $clinic->timezone)->startOfDay()]
                : array_map(fn (int $ago) => $today->subDays($ago), range(0, max(1, (int) $this->option('days')) - 1));

            foreach (Branch::pluck('id') as $branchId) {
                foreach ($dates as $date) {
                    $figures->store($date, (int) $branchId);
                    $rows++;
                }
            }
        }, $this->option('clinic') ? (int) $this->option('clinic') : null);

        $this->info("{$rows} daily summary row(s) rebuilt.");

        return self::SUCCESS;
    }
}
