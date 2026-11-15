<?php

declare(strict_types=1);

namespace App\Core\Settings;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Clinic settings, addressed as "group.key" (for example "clinic.name").
 *
 * Values are read once per clinic per request and kept in memory. This
 * class only stores values; the action that calls it checks the permission
 * and writes the audit entry.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->all()[$name] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $this->loaded = [];

        foreach (DB::connection('clinic')->table('settings')->get() as $row) {
            $raw = $row->is_encrypted && $row->value !== null ? Crypt::decryptString($row->value) : $row->value;
            $this->loaded["{$row->group}.{$row->key}"] = $raw === null ? null : json_decode($raw, true);
        }

        return $this->loaded;
    }

    public function set(string $name, mixed $value, bool $encrypted = false): void
    {
        [$group, $key] = $this->split($name);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $now = now();

        DB::connection('clinic')->table('settings')->upsert(
            [[
                'group' => $group,
                'key' => $key,
                'value' => $encrypted ? Crypt::encryptString($json) : $json,
                'is_encrypted' => $encrypted,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['group', 'key'],
            ['value', 'is_encrypted', 'updated_at'],
        );

        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = null;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $name): array
    {
        $parts = explode('.', $name, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Setting name [{$name}] must look like \"group.key\".");
        }

        return [$parts[0], $parts[1]];
    }
}
