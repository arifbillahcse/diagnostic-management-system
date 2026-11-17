<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\Plan;
use Illuminate\Console\Command;

final class PlansList extends Command
{
    protected $signature = 'plans:list';

    protected $description = 'List the plans (editions) with their prices, branch limit, modules, and number of clinics';

    public function handle(): int
    {
        $rows = Plan::orderBy('sort_order')->orderBy('key')->get()->map(fn (Plan $p): array => [
            $p->key, $p->name, $p->price_monthly->formatted(), $p->price_yearly->formatted(),
            $p->max_branches ?? 'any', implode(', ', $p->moduleKeys()), Clinic::where('plan_id', $p->id)->count(), $p->is_active ? 'yes' : 'no',
        ])->all();

        $this->table(['Key', 'Name', 'Monthly ৳', 'Yearly ৳', 'Branches', 'Modules', 'Clinics', 'On sale'], $rows);

        return self::SUCCESS;
    }
}
