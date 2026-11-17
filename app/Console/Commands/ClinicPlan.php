<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\Actions\ChangePlan;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\Plan;
use Illuminate\Console\Command;

/** Moves a clinic to another plan. Modules not in the new plan are switched off; their data is kept. */
final class ClinicPlan extends Command
{
    protected $signature = 'clinic:plan
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {plan : The plan key (see plans:list)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Move a clinic to another plan; modules outside it are switched off and their data kept';

    public function handle(ChangePlan $change): int
    {
        $clinic = Clinic::find((int) $this->argument('clinic'));
        $plan = Plan::where('key', (string) $this->argument('plan'))->first();

        if ($clinic === null || $plan === null) {
            $this->error($clinic === null ? 'There is no clinic with that ID.' : 'There is no plan with that key.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Move \"{$clinic->name}\" to the {$plan->name} plan?")) {
            return self::FAILURE;
        }

        $result = $change->handle($clinic, $plan);

        $this->info("\"{$clinic->name}\" is now on the {$plan->name} plan.");
        $this->line($result['switched_off'] === [] ? '  No module had to be switched off.' : '  Switched off (data kept): '.implode(', ', $result['switched_off']));

        if ($result['branch_limit'] !== null && $result['branches'] > $result['branch_limit']) {
            $this->warn("  The clinic has {$result['branches']} active branches; the plan allows {$result['branch_limit']}. They stay, but no new branch can be added.");
        }

        return self::SUCCESS;
    }
}
