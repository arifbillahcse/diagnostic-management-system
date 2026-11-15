<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ClinicMigrate extends Command
{
    protected $signature = 'clinic:migrate {--clinic= : Only this clinic (numeric ID)}';

    protected $description = 'Run core and module migrations for every clinic database';

    public function handle(ClinicMigrator $migrator, ClinicManager $manager, PermissionRegistry $permissions): int
    {
        $clinics = Clinic::query()
            ->when($this->option('clinic'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($clinics as $clinic) {
            try {
                $migrator->migrate($clinic);
                $manager->run($clinic, fn () => $permissions->sync());
                $this->log($clinic, 'done', null);
                $this->line("  <info>done</info>    #{$clinic->id} {$clinic->name}");
            } catch (Throwable $e) {
                // One clinic failing must not stop the others; it is reported
                // and recorded so it can be fixed and re-run.
                $failed++;
                $this->log($clinic, 'failed', $e->getMessage());
                $this->line("  <error>failed</error>  #{$clinic->id} {$clinic->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(count($clinics)." clinic(s) processed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function log(Clinic $clinic, string $status, ?string $message): void
    {
        DB::connection('control')->table('provisioning_log')->insert([
            'clinic_id' => $clinic->id,
            'step' => 'migrate',
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
