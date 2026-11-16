<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Backup\ClinicBackup;
use Illuminate\Console\Command;
use Throwable;

final class ClinicVerifyBackup extends Command
{
    protected $signature = 'clinic:verify-backup {file : Path to a .sql.gz or .sql.gz.enc backup}';

    protected $description = 'Restore a backup into a scratch database, compare every table with the manifest, and remove the scratch database';

    public function handle(ClinicBackup $backup): int
    {
        try {
            $result = $backup->verify($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result['ok']) {
            foreach ($result['problems'] as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info('Backup is good: '.number_format($result['rows'])." rows in {$result['tables']} tables restored and matched.");

        return self::SUCCESS;
    }
}
