<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** The platform console's own audit log (control database, append-only). */
final class PlatformAudit
{
    /** @param array<string, mixed> $details */
    public static function record(string $action, ?int $clinicId, array $details = []): void
    {
        DB::connection('control')->table('platform_audit')->insert([
            'platform_user_id' => Auth::guard('platform')->id(),
            'clinic_id' => $clinicId,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
