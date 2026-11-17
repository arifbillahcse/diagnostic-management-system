<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * TEMPORARY, for the trial period. Lists the database updates (migrations)
 * not yet applied and applies them from the portal, so the owner does not
 * need a terminal after each code update.
 *
 * Covers the platform database and the active clinic's database. Other
 * clinics are still updated with `php artisan clinic:migrate`.
 *
 * Off unless config dms.portal_updates.enabled is true.
 * Remove after the trial: see the README.
 */
final class PendingUpdates
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ClinicMigrator $clinicMigrator,
        private readonly ClinicContext $clinics,
        private readonly PermissionRegistry $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('dms.portal_updates.enabled');
    }

    /** Only the clinic owner, and only while the feature is switched on. */
    public function allowed(?User $user = null): bool
    {
        $user ??= Auth::user();

        return self::enabled()
            && $user instanceof User
            && $user->hasRole(Role::OWNER)
            && Gate::forUser($user)->allows(CorePermissions::MODULES_MANAGE);
    }

    /** @return list<string> migration names waiting for the platform database */
    public function control(): array
    {
        return $this->pending('control', [...$this->migrator->paths(), database_path('migrations')]);
    }

    /** @return list<string> migration names waiting for the active clinic's database */
    public function clinic(): array
    {
        return $this->pending('clinic', $this->clinicMigrator->paths());
    }

    /**
     * Apply everything pending: the platform database first, then the active
     * clinic's. Returns what was applied. On a failure the exception says
     * why; what was applied before it stays applied and the rest is still
     * pending, so the update can be repeated after the cause is fixed.
     *
     * @return array{control: list<string>, clinic: list<string>}
     */
    public function apply(): array
    {
        $this->authorize();

        $lock = Cache::lock('portal-updates', 600);

        if (! $lock->get()) {
            throw new RuntimeException('Another update is already running. Wait a minute and try again.');
        }

        try {
            $control = $this->control();

            if ($control !== [] && Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException('The platform database update failed: '.trim(Artisan::output()));
            }

            $clinic = $this->clinic();
            $this->clinicMigrator->migrate($this->clinics->require());
            $this->permissions->sync();

            $this->audit->record('platform.updates_applied', null, [], ['platform' => $control, 'clinic' => $clinic]);

            return ['control' => $control, 'clinic' => $clinic];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function pending(string $connection, array $paths): array
    {
        return $this->migrator->usingConnection($connection, function () use ($paths): array {
            $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];

            return array_values(array_diff(array_keys($this->migrator->getMigrationFiles($paths)), $ran));
        });
    }

    private function authorize(): void
    {
        if (! $this->allowed()) {
            abort(403);
        }
    }
}
