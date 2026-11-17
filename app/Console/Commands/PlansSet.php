<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Modules\ModuleRegistry;
use App\Core\Money\Money;
use App\Core\Tenancy\Plan;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Changes a plan's name, prices, branch limit, or modules. Clinics on it follow at once. */
final class PlansSet extends Command
{
    protected $signature = 'plans:set
        {key : The plan key, for example standard}
        {--name= : The name shown to clinics}
        {--description= : One line about the plan}
        {--monthly= : Monthly price in taka, for example 3000}
        {--yearly= : Yearly price in taka; when only --monthly is given it is 10 times that}
        {--branches= : Most active branches allowed; "any" for no limit}
        {--modules= : Module keys separated by commas, or * for every module}
        {--on-sale= : yes or no}';

    protected $description = 'Set a plan\'s name, prices, branch limit, or modules (php artisan plans:list shows them)';

    public function handle(ModuleRegistry $registry): int
    {
        $plan = Plan::where('key', (string) $this->argument('key'))->first();

        if ($plan === null) {
            $this->error('There is no plan with that key. Run plans:list.');

            return self::FAILURE;
        }

        try {
            foreach (['name', 'description'] as $field) {
                if (filled($this->option($field))) {
                    $plan->{$field} = trim((string) $this->option($field));
                }
            }

            if (filled($this->option('monthly'))) {
                $monthly = Money::fromDecimal((string) $this->option('monthly'));
                $plan->price_monthly = $monthly;
                $plan->price_yearly = filled($this->option('yearly')) ? Money::fromDecimal((string) $this->option('yearly')) : $monthly->times(10);
            } elseif (filled($this->option('yearly'))) {
                $plan->price_yearly = Money::fromDecimal((string) $this->option('yearly'));
            }

            if (filled($this->option('branches'))) {
                $value = (string) $this->option('branches');
                $plan->max_branches = $value === 'any' ? null : max(1, (int) $value);
            }

            if (filled($this->option('on-sale'))) {
                $plan->is_active = $this->option('on-sale') === 'yes';
            }

            if (filled($this->option('modules'))) {
                $keys = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modules')))));
                $unknown = array_diff($keys, [Plan::ALL_MODULES, ...array_keys($registry->all())]);

                if ($unknown !== []) {
                    throw new InvalidArgumentException('Unknown module(s): '.implode(', ', $unknown).'. Run modules:list for the keys.');
                }

                $plan->setModuleKeys($keys);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $plan->save();
        $this->info("Plan {$plan->key} saved.");
        $this->call('plans:list');

        return self::SUCCESS;
    }
}
