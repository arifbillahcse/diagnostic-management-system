<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Illuminate\Support\Facades\DB;
use LogicException;

/** The platform's business numbers (control database), issued under a row lock; never MAX + 1. */
final class PlatformCounter
{
    public function next(string $key, string $period = ''): int
    {
        $db = DB::connection('control');

        if ($db->transactionLevel() === 0) {
            throw new LogicException('A number must be issued inside the transaction that creates its record.');
        }

        $now = now();
        $db->table('platform_counters')->upsert(
            [['counter_key' => $key, 'period' => $period, 'next_value' => 2, 'created_at' => $now, 'updated_at' => $now]],
            ['counter_key', 'period'],
            ['next_value' => DB::raw('next_value + 1'), 'updated_at' => $now],
        );

        return (int) $db->table('platform_counters')->where(['counter_key' => $key, 'period' => $period])->value('next_value') - 1;
    }
}
