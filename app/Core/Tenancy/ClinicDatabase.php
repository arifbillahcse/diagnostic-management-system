<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, drops, and describes the database that belongs to one clinic.
 */
final class ClinicDatabase
{
    public function driver(): string
    {
        return (string) config('database.connections.control.driver');
    }

    public function nameFor(Clinic $clinic): string
    {
        return config('dms.clinics.database_prefix').$clinic->id;
    }

    /** @return array<string, mixed> */
    public function connectionConfig(Clinic $clinic): array
    {
        $base = config('database.connections.control');

        if ($this->driver() === 'sqlite') {
            return ['database' => $this->sqlitePath($clinic)] + $base;
        }

        $ownUser = filled($clinic->db_username);

        return [
            'database' => $clinic->db_name,
            'host' => $clinic->db_host ?: $base['host'],
            'port' => $clinic->db_port ?: $base['port'],
            'username' => $ownUser ? $clinic->db_username : $base['username'],
            'password' => $ownUser ? (string) $clinic->db_password : $base['password'],
        ] + $base;
    }

    public function create(Clinic $clinic): void
    {
        $name = $this->assertSafeName($clinic->db_name);

        if ($this->driver() === 'sqlite') {
            File::ensureDirectoryExists(dirname($this->sqlitePath($clinic)));
            File::put($this->sqlitePath($clinic), '');

            return;
        }

        $control = DB::connection('control');
        $control->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if (! config('dms.clinics.separate_database_users')) {
            return;
        }

        // Each clinic gets its own MySQL user that can reach only its own
        // database, so a query bug cannot read another clinic's data.
        $host = $this->assertSafeHost((string) config('dms.clinics.database_user_host'));
        $password = Str::password(40, symbols: false);
        $quotedPassword = $control->getPdo()->quote($password);
        $grantPattern = str_replace('_', '\\_', $name);

        $control->statement("CREATE USER '{$name}'@'{$host}' IDENTIFIED BY {$quotedPassword}");
        $control->statement("GRANT ALL PRIVILEGES ON `{$grantPattern}`.* TO '{$name}'@'{$host}'");

        $clinic->forceFill(['db_username' => $name, 'db_password' => $password])->save();
    }

    public function drop(Clinic $clinic): void
    {
        $name = $this->assertSafeName($clinic->db_name);

        if ($this->driver() === 'sqlite') {
            File::delete($this->sqlitePath($clinic));

            return;
        }

        $control = DB::connection('control');
        $control->statement("DROP DATABASE IF EXISTS `{$name}`");

        if (filled($clinic->db_username)) {
            $user = $this->assertSafeName($clinic->db_username);
            $host = $this->assertSafeHost((string) config('dms.clinics.database_user_host'));
            $control->statement("DROP USER IF EXISTS '{$user}'@'{$host}'");
        }
    }

    public function sqlitePath(Clinic $clinic): string
    {
        return rtrim((string) config('dms.clinics.sqlite_path'), '/').'/'.$this->assertSafeName($clinic->db_name).'.sqlite';
    }

    private function assertSafeName(string $name): string
    {
        // Database and user names are placed directly into SQL, so they are
        // restricted to characters that cannot break out of the identifier.
        if (! preg_match('/^[a-z0-9_]{1,32}$/', $name)) {
            throw new InvalidArgumentException("Unsafe database name [{$name}].");
        }

        return $name;
    }

    private function assertSafeHost(string $host): string
    {
        if (! preg_match('/^[A-Za-z0-9_.%:-]{1,255}$/', $host)) {
            throw new InvalidArgumentException("Unsafe database host [{$host}].");
        }

        return $host;
    }
}
