<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * Shared steps of the "sample data" commands that fill a test clinic: refuse
 * on a production server unless forced, find the clinic, check the module is
 * on, run as the clinic owner, and put the clock back afterwards.
 *
 * A command extends this, adds `{clinic}` and `{--force}` to its signature,
 * and says what to load. Everything is written through the same actions the
 * screens use, so numbers, audit entries and rules are real.
 */
abstract class SampleDataCommand extends Command
{
    /** The module that must be switched on for this clinic. */
    abstract protected function moduleKey(): string;

    /**
     * Writes the sample data into the active clinic.
     *
     * @return list<string> lines for the summary
     */
    abstract protected function load(Clinic $clinic): array;

    public function handle(ClinicManager $manager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This server is in production. Add --force only for a clinic that will never hold real data.');

            return self::FAILURE;
        }

        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Load sample data into \"{$clinic->name}\"? It cannot be removed afterwards.")) {
            return self::FAILURE;
        }

        try {
            $lines = $manager->run($clinic, function () use ($clinic): array {
                if (! app(ModuleState::class)->isEnabled($this->moduleKey())) {
                    throw new RuntimeException("The {$this->moduleKey()} module is switched off for this clinic. Turn it on from the Modules page first.");
                }

                Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());

                return $this->load($clinic);
            });
        } catch (Throwable $e) {
            $this->error("Sample data was not loaded: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->info("Sample data loaded into \"{$clinic->name}\".");

        foreach ($lines as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }

    /** Make "now" a moment in the past, so the records look like they were made then. */
    protected function travelTo(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    protected function backToNow(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
