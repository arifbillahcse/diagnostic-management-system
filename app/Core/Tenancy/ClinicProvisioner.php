<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates a clinic end to end: control record, database, tables, first data.
 * If any step fails, everything it created is removed again.
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
    ): Clinic {
        $domain = ClinicDomain::normalize($domain);

        if (! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            throw new InvalidArgumentException("[{$domain}] is not a valid domain.");
        }

        if (ClinicDomain::where('domain', $domain)->exists()) {
            throw new InvalidArgumentException("The domain [{$domain}] already belongs to a clinic.");
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

        try {
            $clinic->update(['db_name' => $this->databases->nameFor($clinic)]);

            $this->databases->create($clinic);
            $databaseCreated = true;
            $this->log($clinic, 'database', 'done');

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

            $clinic->delete();

            throw $e;
        }

        return $clinic;
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
