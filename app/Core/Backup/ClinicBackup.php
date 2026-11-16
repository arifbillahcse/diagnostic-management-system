<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Tenancy\Clinic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backs up a MySQL database to a compressed (and optionally encrypted)
 * file, and proves a backup can be restored.
 *
 * A manifest is written beside every backup with the row count of each
 * table at the moment of the dump. Verifying a backup restores it into a
 * scratch database and compares the counts, so "we have backups" is
 * something that has been tested, not assumed.
 */
final class ClinicBackup
{
    /** @return array{file: string, manifest: string, tables: int, rows: int, encrypted: bool} */
    public function create(string $database, string $directory, ?Clinic $clinic = null): array
    {
        $this->assertMySql();
        $this->assertName($database);
        File::ensureDirectoryExists($directory, 0700);

        $encrypted = filled($this->passphrase());
        $file = rtrim($directory, '/').'/'.$database.'-'.now('UTC')->format('Ymd-His').'.sql.gz'.($encrypted ? '.enc' : '');

        // Counted inside one consistent snapshot, like the dump itself.
        $counts = $this->rowCounts($database);

        $command = 'set -o pipefail; mysqldump --single-transaction --quick --triggers --routines --no-tablespaces --set-gtid-purged=OFF '
            .$this->connectionFlags().' '.escapeshellarg($database).' | gzip -c'
            .($encrypted ? ' | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:DMS_BACKUP_PASSPHRASE' : '')
            .' > '.escapeshellarg($file);

        $this->run($command);
        chmod($file, 0600);

        $manifest = [
            'database' => $database,
            'clinic_id' => $clinic?->id,
            'clinic_name' => $clinic?->name,
            'created_at' => now('UTC')->toIso8601String(),
            'core_version' => config('dms.version'),
            'encrypted' => $encrypted,
            'bytes' => filesize($file),
            'sha256' => hash_file('sha256', $file),
            'tables' => $counts,
        ];

        File::put($file.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($file.'.manifest.json', 0600);

        return ['file' => $file, 'manifest' => $file.'.manifest.json', 'tables' => count($counts), 'rows' => array_sum($counts), 'encrypted' => $encrypted];
    }

    /**
     * Restore a backup into a scratch database, compare every table's row
     * count with the manifest, then drop the scratch database.
     *
     * @return array{ok: bool, tables: int, rows: int, problems: list<string>}
     */
    public function verify(string $file): array
    {
        $this->assertMySql();

        if (! is_file($file) || ! is_file($file.'.manifest.json')) {
            throw new RuntimeException('The backup file or its manifest (.manifest.json) was not found.');
        }

        $manifest = json_decode((string) file_get_contents($file.'.manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $problems = [];

        if (hash_file('sha256', $file) !== ($manifest['sha256'] ?? null)) {
            return ['ok' => false, 'tables' => 0, 'rows' => 0, 'problems' => ['The file does not match its manifest: it was changed or damaged after the backup.']];
        }

        if (($manifest['encrypted'] ?? false) && blank($this->passphrase())) {
            throw new RuntimeException('This backup is encrypted. Set BACKUP_PASSPHRASE to the passphrase it was made with.');
        }

        $scratch = 'dms_verify_'.strtolower(Str::random(10));
        $control = DB::connection('control');
        $control->statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $this->run('set -o pipefail; '
                .($manifest['encrypted'] ? 'openssl enc -d -aes-256-cbc -pbkdf2 -pass env:DMS_BACKUP_PASSPHRASE -in '.escapeshellarg($file) : 'cat '.escapeshellarg($file))
                .' | gunzip -c | mysql '.$this->connectionFlags().' '.escapeshellarg($scratch));

            $restored = $this->rowCounts($scratch);

            foreach ($manifest['tables'] as $table => $expected) {
                if (! array_key_exists($table, $restored)) {
                    $problems[] = "Table {$table} is missing from the restored database.";
                } elseif ($restored[$table] !== $expected) {
                    $problems[] = "Table {$table}: {$restored[$table]} rows restored, {$expected} expected.";
                }
            }
        } finally {
            $control->statement("DROP DATABASE IF EXISTS `{$scratch}`");
        }

        return ['ok' => $problems === [], 'tables' => count($manifest['tables']), 'rows' => array_sum($manifest['tables']), 'problems' => $problems];
    }

    /** Delete backups (and their manifests) older than the given number of days. */
    public function prune(string $directory, int $keepDays): int
    {
        $deleted = 0;

        foreach (File::glob(rtrim($directory, '/').'/*.sql.gz*') as $path) {
            if (filemtime($path) < now()->subDays($keepDays)->getTimestamp()) {
                File::delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array<string, int> table => exact row count */
    private function rowCounts(string $database): array
    {
        $this->assertName($database);
        $control = DB::connection('control');
        $counts = [];

        foreach ($control->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name', [$database, 'BASE TABLE']) as $table) {
            $counts[$table->name] = (int) $control->selectOne("SELECT COUNT(*) AS total FROM `{$database}`.`{$table->name}`")->total;
        }

        return $counts;
    }

    private function connectionFlags(): string
    {
        $config = config('database.connections.control');

        // The password travels in the environment (MYSQL_PWD), never on the
        // command line, where other users of the server could read it.
        return '--host='.escapeshellarg((string) $config['host']).' --port='.escapeshellarg((string) $config['port']).' --user='.escapeshellarg((string) $config['username']);
    }

    private function run(string $command): void
    {
        // bash, not sh: "pipefail" makes the whole pipeline fail if mysqldump
        // fails, so a broken dump can never be saved as a good backup.
        $process = new Process(['bash', '-c', $command], null, [
            'MYSQL_PWD' => (string) config('database.connections.control.password'),
            'DMS_BACKUP_PASSPHRASE' => (string) $this->passphrase(),
        ]);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Backup command failed: '.trim($process->getErrorOutput()));
        }
    }

    private function passphrase(): ?string
    {
        return config('dms.backup.passphrase');
    }

    private function assertMySql(): void
    {
        if (DB::connection('control')->getDriverName() !== 'mysql') {
            throw new RuntimeException('Backups need MySQL.');
        }
    }

    private function assertName(string $database): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new RuntimeException("Unsafe database name [{$database}].");
        }
    }
}
