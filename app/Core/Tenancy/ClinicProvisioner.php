<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates a clinic end to end: control record, database, tables, first data.
 * If any step fails, everything it created is removed again.
 *
 * Given an existing database (shared hosting, where the application may not
 * create one), it uses that database instead of creating it. The database
 * must be empty, and on failure only the tables are removed.
 */
final class ClinicProvisioner
{
    public function __construct(
        private readonly ClinicDatabase $databases,
        private readonly ClinicMigrator $migrator,
        private readonly ClinicManager $manager,
        private readonly ClinicSetup $setup,
    ) {}

    public function provision(
        string $name,
        string $domain,
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
        ?string $planKey = null,
        ?string $database = null,
        ?string $databaseUsername = null,
        ?string $databasePassword = null,
    ): Clinic {
        $domain = ClinicDomain::normalize($domain);

        if (! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            throw new InvalidArgumentException("[{$domain}] is not a valid domain.");
        }

        if (ClinicDomain::where('domain', $domain)->exists()) {
            throw new InvalidArgumentException("The domain [{$domain}] already belongs to a clinic.");
        }

        if (filled($database) && Clinic::where('db_name', $database)->exists()) {
            throw new InvalidArgumentException("The database [{$database}] already belongs to a clinic.");
        }

        $plan = $this->plan($planKey);

        $clinic = Clinic::create([
            'name' => $name,
            'status' => ClinicStatus::Trial,
            'db_name' => 'pending_'.strtolower((string) Str::ulid()),
            'timezone' => config('dms.clinics.default_timezone'),
            'plan_id' => $plan->id,
        ]);

        $databaseCreated = false;
        $databaseAdopted = false;

        try {
            if (filled($database)) {
                $this->databases->adopt($clinic, $database, $databaseUsername, $databasePassword);
                $this->assertReachableAndEmpty($clinic);
                $databaseAdopted = true;
                $this->log($clinic, 'database', 'done', 'existing database');
            } else {
                $clinic->update(['db_name' => $this->databases->nameFor($clinic)]);

                $this->databases->create($clinic);
                $databaseCreated = true;
                $this->log($clinic, 'database', 'done');
            }

            $this->migrator->migrate($clinic);
            $this->log($clinic, 'migrate', 'done');

            $this->manager->run($clinic, fn () => $this->setup->run($clinic, $ownerName, $ownerEmail, $ownerPassword));
            $this->log($clinic, 'setup', 'done');

            $clinic->domains()->create(['domain' => $domain, 'is_primary' => true]);
            $this->log($clinic, 'domain', 'done', $domain);
        } catch (Throwable $e) {
            $this->log($clinic, 'failed', 'failed', "{$name}: {$e->getMessage()}");

            if ($databaseCreated) {
                rescue(fn () => $this->databases->drop($clinic));
            }

            if ($databaseAdopted) {
                // It was empty before we started, so every table is ours.
                rescue(fn () => $this->manager->run($clinic, fn () => Schema::connection('clinic')->dropAllTables()));
            }

            $clinic->delete();

            throw $e;
        }

        return $clinic;
    }

    private function assertReachableAndEmpty(Clinic $clinic): void
    {
        try {
            $tables = $this->manager->run($clinic, fn () => Schema::connection('clinic')->getTables());
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                "Could not open the database [{$clinic->db_name}]: {$e->getMessage()}", previous: $e,
            );
        }

        if ($tables !== []) {
            throw new InvalidArgumentException(
                "The database [{$clinic->db_name}] is not empty. A clinic needs a database of its own.",
            );
        }
    }

    private function plan(?string $key): Plan
    {
        $default = (string) config('dms.clinics.default_plan');
        $key ??= $default;

        $plan = Plan::where('key', $key)->first();

        if ($plan === null && $key === $default) {
            // First clinic on a new installation: create the catch-all plan.
            $plan = Plan::create(['key' => $key, 'name' => Str::headline($key)]);
            $plan->setModuleKeys([Plan::ALL_MODULES]);
        }

        return $plan ?? throw new InvalidArgumentException("There is no plan called [{$key}].");
    }

    private function log(Clinic $clinic, string $step, string $status, ?string $message = null): void
    {
        DB::connection('control')->table('provisioning_log')->insert([
            'clinic_id' => $clinic->id,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
