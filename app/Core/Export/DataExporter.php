<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Exports the clinic's own data as CSV, table by table, so a clinic can
 * always take its records with it.
 *
 * Rows are read in chunks and written straight to the output, so a large
 * table does not have to fit in memory.
 */
final class DataExporter
{
    /** Columns that are never exported. */
    private const HIDDEN = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** Core tables, in the order they are listed. */
    private const CORE = [
        'branches' => 'Branches',
        'users' => 'Users (without passwords)',
        'roles' => 'Roles',
        'patients' => 'Patients',
        'audit_logs' => 'Audit log',
    ];

    /** @var array<string, string>|null worked out once per request */
    private ?array $datasets = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleState $state,
    ) {}

    /**
     * Everything that can be exported: core tables, plus the tables of
     * every module installed in this clinic (switched on or off, because
     * a switched-off module's data still belongs to the clinic).
     *
     * @return array<string, string> table => label
     */
    public function datasets(): array
    {
        if ($this->datasets !== null) {
            return $this->datasets;
        }

        $datasets = self::CORE;

        foreach (array_keys($this->modules->all()) as $key) {
            if ($this->state->isInstalled($key)) {
                foreach ($this->modules->module($key)->exports() as $table => $label) {
                    $datasets[$table] = $this->modules->get($key)->name.': '.$label;
                }
            }
        }

        return $this->datasets = array_filter($datasets, fn (string $label, string $table): bool => Schema::connection('clinic')->hasTable($table), ARRAY_FILTER_USE_BOTH);
    }

    public function count(string $table): int
    {
        $this->assertKnown($table);

        return DB::connection('clinic')->table($table)->count();
    }

    /**
     * Row counts for the export screen. On MySQL these are the database's
     * own estimates, read in one query; counting millions of rows exactly
     * just to draw a list would take seconds.
     *
     * @return array<string, int> table => rows (approximate on MySQL)
     */
    public function approximateCounts(): array
    {
        $tables = array_keys($this->datasets());
        $db = DB::connection('clinic');

        if ($db->getDriverName() !== 'mysql') {
            return array_combine($tables, array_map(fn (string $t): int => $db->table($t)->count(), $tables));
        }

        $estimates = collect($db->select('SELECT table_name AS name, table_rows AS total FROM information_schema.tables WHERE table_schema = DATABASE()'))
            ->pluck('total', 'name');

        return array_combine($tables, array_map(fn (string $t): int => (int) ($estimates[$t] ?? 0), $tables));
    }

    /**
     * Write one table as CSV to an open stream.
     *
     * @param  resource  $out
     * @return int rows written
     */
    public function write(string $table, $out): int
    {
        $this->assertKnown($table);

        $columns = array_values(array_diff(Schema::connection('clinic')->getColumnListing($table), self::HIDDEN));
        $rows = 0;

        fwrite($out, "\xEF\xBB\xBF"); // lets Excel read it as UTF-8
        fputcsv($out, $columns, ',', '"', '');

        DB::connection('clinic')->table($table)->select($columns)->orderBy('id')->chunkById(1000, function ($chunk) use ($out, $columns, &$rows): void {
            foreach ($chunk as $row) {
                fputcsv($out, array_map(fn (string $column) => $this->cell($row->{$column}), $columns), ',', '"', '');
                $rows++;
            }
        });

        return $rows;
    }

    /**
     * A spreadsheet treats text starting with "=", "+", "-", or "@" as a
     * formula. A patient name typed that way could then run when the file
     * is opened, so such text is prefixed with an apostrophe. Real negative
     * numbers are left alone.
     */
    private function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $risky = in_array($value[0], ['=', '+', '@', "\t", "\r"], true)
            || ($value[0] === '-' && ! is_numeric($value));

        return $risky ? "'".$value : $value;
    }

    private function assertKnown(string $table): void
    {
        if (! array_key_exists($table, $this->datasets())) {
            throw new InvalidArgumentException("[{$table}] is not an exportable table.");
        }
    }
}
