<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PlatformDoctor extends Command
{
    protected $signature = 'platform:doctor';

    protected $description = 'Check that this installation is configured safely for real patient data';

    public function handle(): int
    {
        $database = config('database.connections.control');
        $mysql = ($database['driver'] ?? null) === 'mysql';

        $checks = [
            ['Debug mode is off', config('app.debug') === false, 'Set APP_DEBUG=false. Debug pages can show passwords and patient data.'],
            ['Environment is production', config('app.env') === 'production', 'Set APP_ENV=production.'],
            ['An application key is set', filled(config('app.key')), 'Run: php artisan key:generate. Keep the key safe: encrypted settings and clinic database passwords need it.'],
            ['The site address uses HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to an https:// address and serve the site over HTTPS only.'],
            ['Session cookies are HTTPS-only', config('session.secure') === true, 'Set SESSION_SECURE_COOKIE=true.'],
            ['The database is MySQL', $mysql, 'Set DB_DRIVER=mysql. SQLite is for the fast tests only.'],
            ['Each clinic has its own database user', (bool) config('dms.clinics.separate_database_users'), 'Set CLINIC_DB_SEPARATE_USERS=true.'],
            ['Queued jobs do not run inside web requests', config('queue.default') !== 'sync', 'Set QUEUE_CONNECTION=database and run a queue worker.'],
            ['A backup folder is set', filled(config('dms.backup.path')), 'Set BACKUP_PATH, and copy that folder to another place every night.'],
            ['Backups are encrypted', filled(config('dms.backup.passphrase')), 'Set BACKUP_PASSPHRASE and store the passphrase somewhere other than this server.'],
        ];

        if ($mysql) {
            try {
                $mode = (string) DB::connection('control')->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
                $checks[] = ['MySQL strict mode is on', str_contains($mode, 'STRICT_TRANS_TABLES'), 'Enable STRICT_TRANS_TABLES so MySQL never silently cuts or changes data.'];
            } catch (Throwable $e) {
                $checks[] = ['The control database can be reached', false, $e->getMessage()];
            }
        }

        $failed = 0;

        foreach ($checks as [$label, $ok, $fix]) {
            $this->line(($ok ? '  <info>ok</info>      ' : '  <error>fix</error>     ').$label);

            if (! $ok) {
                $failed++;
                $this->line("            {$fix}");
            }
        }

        $this->newLine();
        $this->line($failed === 0 ? 'All checks passed.' : "{$failed} thing(s) to fix before real patient data is entered.");
        $this->line('Not checked here: HTTPS on the web server, the firewall, operating system updates, and off-site copies of backups.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
