<?php

declare(strict_types=1);

namespace App\Platform;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A short picture of how a clinic uses the system, read from its own
 * database: bills this month, active users and branches, the last sign-in,
 * and the modules switched on. Counts only, never patient data.
 */
final class ClinicUsage
{
    /** @return array{bills_this_month: ?int, users: ?int, branches: ?int, last_sign_in: ?string, modules: list<string>, error: ?string} */
    public function for(Clinic $clinic): array
    {
        try {
            return app(ClinicManager::class)->run($clinic, function () use ($clinic): array {
                $db = DB::connection('clinic');
                $monthStart = now($clinic->timezone ?: 'Asia/Dhaka')->startOfMonth()->utc();

                return [
                    'bills_this_month' => Schema::connection('clinic')->hasTable('fd_orders') ? $db->table('fd_orders')->where('ordered_at', '>=', $monthStart)->count() : null,
                    'users' => $db->table('users')->where('is_active', true)->whereNull('deleted_at')->count(),
                    'branches' => $db->table('branches')->where('is_active', true)->count(),
                    'last_sign_in' => $db->table('users')->max('last_login_at'),
                    'modules' => $db->table('modules')->where('status', 'enabled')->orderBy('module_key')->pluck('module_key')->all(),
                    'error' => null,
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return ['bills_this_month' => null, 'users' => null, 'branches' => null, 'last_sign_in' => null, 'modules' => [], 'error' => 'The clinic database could not be read.'];
        }
    }
}
