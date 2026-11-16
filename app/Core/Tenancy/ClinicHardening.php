<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the database itself refuse changes to append-only tables (the
 * audit log, payments, invoice lines, verified result versions, ...).
 *
 * The application already never updates or deletes those rows. This is the
 * second lock: if a bug or an injected query tried to, MySQL raises an
 * error. It does not stop someone who holds the database password; they
 * could remove the triggers.
 *
 * MySQL only. Run with a clinic active. Safe to run again.
 *
 * Some hosting (shared cPanel accounts with binary logging on) does not
 * let the application create triggers at all. That must not stop a clinic
 * from being set up, so a refusal is recorded and reported, not thrown.
 */
#[Singleton]
final class ClinicHardening
{
    private const CORE_TABLES = ['audit_logs'];

    private ?string $skipped = null;

    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @return list<string> every append-only table the code base defines */
    public function tables(): array
    {
        $tables = self::CORE_TABLES;

        foreach (array_keys($this->modules->all()) as $key) {
            if (class_exists($this->modules->get($key)->entry)) {
                $tables = [...$tables, ...$this->modules->module($key)->appendOnlyTables()];
            }
        }

        return array_values(array_unique($tables));
    }

    /** Why the last apply() protected nothing, or null when it worked. */
    public function skippedReason(): ?string
    {
        return $this->skipped;
    }

    /** @return list<string> the tables that were protected */
    public function apply(): array
    {
        $this->skipped = null;

        if (DB::connection('control')->getDriverName() !== 'mysql') {
            return [];
        }

        $database = $this->clinics->require()->db_name;

        if (! preg_match('/^[a-z0-9_]{1,64}$/', $database)) {
            return [];
        }

        $tables = array_values(array_filter(
            $this->tables(),
            fn (string $table): bool => preg_match('/^[a-z0-9_]{1,50}$/', $table) === 1
                && Schema::connection('clinic')->hasTable($table),
        ));

        // The control connection first: with binary logging on, MySQL lets
        // only a privileged user create triggers. Then the clinic's own
        // user, for hosting where the control user cannot reach the clinic
        // database.
        foreach (['control', 'clinic'] as $connection) {
            try {
                $this->protect(DB::connection($connection), $database, $tables);
                $this->skipped = null;

                return $tables;
            } catch (QueryException $e) {
                $this->skipped = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            }
        }

        Log::warning("Append-only triggers could not be created on [{$database}].", ['reason' => $this->skipped]);

        return [];
    }

    /** @param list<string> $tables */
    private function protect(ConnectionInterface $connection, string $database, array $tables): void
    {
        foreach ($tables as $table) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
                $trigger = "`{$database}`.`{$table}_no_{$suffix}`";

                $connection->unprepared("DROP TRIGGER IF EXISTS {$trigger}");
                $connection->unprepared(
                    "CREATE TRIGGER {$trigger} BEFORE {$event} ON `{$database}`.`{$table}` FOR EACH ROW ".
                    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'"
                );
            }
        }
    }
}
