<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
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
 */
final class ClinicHardening
{
    private const CORE_TABLES = ['audit_logs'];

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

    /** @return list<string> the tables that were protected */
    public function apply(): array
    {
        if (DB::connection('control')->getDriverName() !== 'mysql') {
            return [];
        }

        $database = $this->clinics->require()->db_name;

        if (! preg_match('/^[a-z0-9_]{1,64}$/', $database)) {
            return [];
        }

        // Triggers are created through the control connection: with binary
        // logging on, MySQL lets only a privileged user create them.
        $control = DB::connection('control');
        $protected = [];

        foreach ($this->tables() as $table) {
            if (! preg_match('/^[a-z0-9_]{1,50}$/', $table) || ! Schema::connection('clinic')->hasTable($table)) {
                continue;
            }

            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
                $trigger = "`{$database}`.`{$table}_no_{$suffix}`";

                $control->unprepared("DROP TRIGGER IF EXISTS {$trigger}");
                $control->unprepared(
                    "CREATE TRIGGER {$trigger} BEFORE {$event} ON `{$database}`.`{$table}` FOR EACH ROW ".
                    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'"
                );
            }

            $protected[] = $table;
        }

        return $protected;
    }
}
