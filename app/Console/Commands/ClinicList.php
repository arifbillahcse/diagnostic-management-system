<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\Clinic;
use Illuminate\Console\Command;

final class ClinicList extends Command
{
    protected $signature = 'clinic:list';

    protected $description = 'List all clinics';

    public function handle(): int
    {
        $rows = Clinic::with('domains', 'plan')->orderBy('id')->get()->map(fn (Clinic $clinic): array => [
            $clinic->id,
            $clinic->name,
            $clinic->status->value,
            $clinic->domains->pluck('domain')->implode(', '),
            $clinic->db_name,
            $clinic->plan?->key ?? '-',
        ]);

        $this->table(['ID', 'Name', 'Status', 'Domains', 'Database', 'Plan'], $rows);

        return self::SUCCESS;
    }
}
