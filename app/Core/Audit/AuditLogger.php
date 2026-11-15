<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit entries. Call it inside the same database transaction as the
 * change being recorded.
 */
final class AuditLogger
{
    private const REDACTED = '[hidden]';

    /** Attribute names whose values must never be stored in the trail. */
    private const SECRET_KEYS = ['password', 'remember_token', 'db_password', 'secret', 'token', 'api_key'];

    /**
     * @param  array<string, mixed>  $before  Values before the change (changed fields only)
     * @param  array<string, mixed>  $after  Values after the change (changed fields only)
     * @param  array<string, mixed>  $context  Extra facts, for example a reason
     */
    public function record(string $action, ?Model $entity = null, array $before = [], array $after = [], array $context = []): AuditLog
    {
        $user = Auth::user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'occurred_at' => now(),
            'actor_type' => $user instanceof User ? 'user' : 'system',
            'actor_id' => $user instanceof User ? $user->id : null,
            'actor_name' => $user instanceof User ? $user->name : 'System',
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'branch_id' => $user instanceof User ? $user->default_branch_id : null,
            'before' => $this->clean($before) ?: null,
            'after' => $this->clean($after) ?: null,
            'context' => $this->clean($context) ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * Reduce two attribute sets to the fields that differ.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(array $old, array $new): array
    {
        $before = $after = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $was = $old[$key] ?? null;
            $is = $new[$key] ?? null;

            if ($was != $is) {
                $before[$key] = $was;
                $after[$key] = $is;
            }
        }

        return [$before, $after];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at'], true)) {
                unset($values[$key]);
            } elseif (in_array($key, self::SECRET_KEYS, true)) {
                $values[$key] = self::REDACTED;
            }
        }

        return $values;
    }
}
