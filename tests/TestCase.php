<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth\PermissionChecker;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicDatabase;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicProvisioner;
use App\Core\Tenancy\Http\EnsureSessionBelongsToClinic;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Every test starts with an empty control database and no clinics.
 * The same tests run on SQLite (fast, default) and on MySQL (DB_DRIVER=mysql).
 */
abstract class TestCase extends BaseTestCase
{
    public const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        config(['dms.clinics.sqlite_path' => storage_path('framework/testing/clinics/'.getmypid())]);

        if ($this->onMySql()) {
            $this->dropLeftoverClinicDatabases();
            Artisan::call('migrate:fresh', ['--force' => true]);
        } else {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        rescue(function (): void {
            app(ClinicManager::class)->deactivate();

            foreach (Clinic::all() as $clinic) {
                app(ClinicDatabase::class)->drop($clinic);
            }

            File::deleteDirectory(config('dms.clinics.sqlite_path'));
        }, report: false);

        parent::tearDown();
    }

    protected function onMySql(): bool
    {
        return config('database.connections.control.driver') === 'mysql';
    }

    /** Create a clinic with an owner. The clinic is not left active. */
    protected function createClinic(string $slug = 'alpha', ?string $plan = null): Clinic
    {
        return app(ClinicProvisioner::class)->provision(
            name: ucfirst($slug).' Diagnostics',
            domain: "{$slug}.test",
            ownerName: ucfirst($slug).' Owner',
            ownerEmail: "owner@{$slug}.test",
            ownerPassword: self::PASSWORD,
            planKey: $plan,
        );
    }

    /** Create a clinic and make it the active one. */
    protected function useClinic(string $slug = 'alpha', ?string $plan = null): Clinic
    {
        $clinic = $this->createClinic($slug, $plan);
        app(ClinicManager::class)->activate($clinic);

        return $clinic;
    }

    protected function owner(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail();
    }

    /** Create a user in the active clinic holding the given roles in every branch. */
    protected function userWithRoles(string ...$roleKeys): User
    {
        $user = User::create([
            'name' => 'Staff '.uniqid(),
            'email' => uniqid('staff').'@example.test',
            'password' => self::PASSWORD,
            'is_active' => true,
        ]);

        foreach ($roleKeys as $key) {
            DB::connection('clinic')->table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => Role::where('key', $key)->valueOrFail('id'),
                'branch_id' => null,
            ]);
        }

        app(PermissionChecker::class)->flush();

        return $user;
    }

    /** Sign a user of the active clinic in, as the login screen would. */
    protected function signIn(User $user): static
    {
        // Start from an empty session, as a different person's browser would.
        $this->flushSession();

        return $this->actingAs($user)->withSession([
            EnsureSessionBelongsToClinic::SESSION_KEY => app(ClinicContext::class)->require()->id,
        ]);
    }

    /** Switch a module off, first switching off every module that depends on it. */
    protected function disableModule(string $key): void
    {
        foreach (app(ModuleRegistry::class)->dependentsOf($key) as $dependent) {
            $this->disableModule($dependent);
        }

        app(ModuleManager::class)->disable($key);
    }

    /** Point the module system at the fixture modules in tests/Fixtures/<set>. */
    protected function useModuleFixtures(string $set = 'ModuleSet'): void
    {
        config(['dms.modules_path' => base_path("tests/Fixtures/{$set}")]);

        foreach ([ModuleRegistry::class, ModuleState::class, PermissionRegistry::class, PermissionChecker::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    private function dropLeftoverClinicDatabases(): void
    {
        $control = DB::connection('control');
        $prefix = (string) config('dms.clinics.database_prefix');
        $host = (string) config('dms.clinics.database_user_host');

        foreach ($control->select('SHOW DATABASES') as $row) {
            $name = $row->Database;

            if (str_starts_with($name, $prefix) && preg_match('/^[a-z0-9_]+$/', $name)) {
                $control->statement("DROP DATABASE `{$name}`");
                $control->statement("DROP USER IF EXISTS '{$name}'@'{$host}'");
            }
        }
    }
}
