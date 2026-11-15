<?php

declare(strict_types=1);

namespace App\Core\Numbering;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues the next value of a named counter.
 *
 * Must be called inside the database transaction that creates the record
 * the number is for. If that transaction fails, the counter rolls back with
 * it, so no number is lost and no two records share one.
 */
final class CounterService
{
    public function next(string $key, int $branchId = 0, string $period = ''): int
    {
        $db = DB::connection('clinic');

        if ($db->transactionLevel() === 0) {
            throw new LogicException('A number must be issued inside the transaction that creates its record.');
        }

        $where = ['counter_key' => $key, 'branch_id' => $branchId, 'period' => $period];
        $now = now();

        // One statement that either creates the counter or advances it.
        // It takes an exclusive row lock straight away, so simultaneous
        // callers queue up instead of deadlocking, and the lock is held
        // until the surrounding transaction ends.
        $db->table('counters')->upsert(
            [$where + ['next_value' => 2, 'created_at' => $now, 'updated_at' => $now]],
            ['counter_key', 'branch_id', 'period'],
            ['next_value' => DB::raw('next_value + 1'), 'updated_at' => $now],
        );

        return (int) $db->table('counters')->where($where)->value('next_value') - 1;
    }
}
