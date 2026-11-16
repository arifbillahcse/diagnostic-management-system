<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Backup\ClinicBackup;
use App\Core\Tenancy\Clinic;
use Illuminate\Console\Command;
use Throwable;

final class ClinicBackupCommand extends Command
{
    protected $signature = 'clinic:backup
        {--clinic= : Only this clinic (numeric ID). The control database is always included}
        {--path= : Folder to write to. Defaults to BACKUP_PATH}
        {--verify : Restore each backup into a scratch database and check it}';

    protected $description = 'Back up the control database and every clinic database, each to its own file with a manifest';

    public function handle(ClinicBackup $backup): int
    {
        $path = $this->option('path') ?: config('dms.backup.path');

        if (blank($path)) {
            $this->error('No backup folder. Set BACKUP_PATH in .env or pass --path.');

            return self::FAILURE;
        }

        if (blank(config('dms.backup.passphrase'))) {
            $this->warn('BACKUP_PASSPHRASE is not set: these backups are NOT encrypted. They contain patient data.');
        }

        $targets = [[config('database.connections.control.database'), null]];

        foreach (Clinic::query()->when($this->option('clinic'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get() as $clinic) {
            $targets[] = [$clinic->db_name, $clinic];
        }

        $failed = 0;

        foreach ($targets as [$database, $clinic]) {
            try {
                $result = $backup->create($database, $path, $clinic);
                $line = sprintf('%s: %s rows in %d tables, %s', $database, number_format($result['rows']), $result['tables'], basename($result['file']));

                if ($this->option('verify')) {
                    $check = $backup->verify($result['file']);

                    if (! $check['ok']) {
                        throw new \RuntimeException('restore check failed: '.implode(' ', $check['problems']));
                    }

                    $line .= ', restore checked';
                }

                $this->line("  <info>done</info>    {$line}");
            } catch (Throwable $e) {
                $failed++;
                $this->line("  <error>failed</error>  {$database}: {$e->getMessage()}");
            }
        }

        $pruned = $backup->prune($path, (int) config('dms.backup.keep_days'));
        $this->newLine();
        $this->line(count($targets)." database(s) processed, {$failed} failed, {$pruned} old file(s) removed.");
        $this->line('Uploaded files (logos, signatures) are in storage/app/private/clinics and must be copied separately.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
