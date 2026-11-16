<?php

declare(strict_types=1);

namespace App\Core\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Numeric limits attached to roles, such as the largest discount percent a
 * role may give. A user's limit is the highest among their roles; the owner
 * role has no limit.
 */
final class RoleLimits
{
    public const UNLIMITED = PHP_INT_MAX;

    /** @var array<string, int> "user id:key" => limit in hundredths */
    private array $cache = [];

    /**
     * The user's limit in hundredths (10.5 is returned as 1050), or 0 when
     * none of their roles defines one.
     */
    public function hundredthsFor(User $user, string $key): int
    {
        return $this->cache["{$user->id}:{$key}"] ??= $this->load($user, $key);
    }

    /**
     * Set limits for roles that do not have a value yet.
     *
     * @param  array<string, array<string, string>>  $defaults  role key => [limit key => value]
     */
    public function applyDefaults(array $defaults): void
    {
        $now = now();

        foreach ($defaults as $roleKey => $limits) {
            $roleId = Role::where('key', $roleKey)->value('id');

            foreach ($roleId === null ? [] : $limits as $key => $value) {
                DB::connection('clinic')->table('role_limits')->insertOrIgnore([
                    'role_id' => $roleId, 'limit_key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function load(User $user, string $key): int
    {
        $roles = DB::connection('clinic')->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.key', 'roles.id');

        if ($roles->contains(Role::OWNER)) {
            return self::UNLIMITED;
        }

        $values = DB::connection('clinic')->table('role_limits')
            ->whereIn('role_id', $roles->keys()->all())
            ->where('limit_key', $key)
            ->pluck('value');

        $best = 0;

        foreach ($values as $value) {
            if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', (string) $value, $m)) {
                $best = max($best, ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0'));
            }
        }

        return $best;
    }
}
