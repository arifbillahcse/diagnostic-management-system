<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\PermissionChecker;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\EnsureModuleEnabled;
use App\Core\Modules\Entitlements;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicDatabase;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicSwitched;
use App\Core\Tenancy\Http\EnsureSessionBelongsToClinic;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClinicContext::class);
        $this->app->singleton(ClinicDatabase::class);
        $this->app->singleton(ClinicManager::class);
        $this->app->singleton(ModuleState::class);
        $this->app->singleton(Entitlements::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(PermissionChecker::class);
        $this->app->singleton(Settings::class);

        $this->app->singleton(ModuleRegistry::class, fn (): ModuleRegistry => new ModuleRegistry(
            (string) config('dms.modules_path'),
            (string) config('dms.version'),
        ));
    }

    public function boot(Router $router): void
    {
        // `php artisan migrate` manages the control database only. Clinic
        // databases are migrated with `php artisan clinic:migrate`.
        $this->loadMigrationsFrom(database_path('migrations/control'));

        Password::defaults(fn () => Password::min(10));

        $router->aliasMiddleware('module', EnsureModuleEnabled::class);

        $this->forgetClinicStateOnSwitch();
        $this->registerPermissionGate();
        $this->registerLoginHooks();
        $this->makeQueuedJobsClinicAware();
        $this->bootModules();
    }

    /** Per-clinic values held in memory must never leak into the next clinic. */
    private function forgetClinicStateOnSwitch(): void
    {
        Event::listen(ClinicSwitched::class, function (): void {
            $this->app->make(ModuleState::class)->flush();
            $this->app->make(Entitlements::class)->flush();
            $this->app->make(PermissionChecker::class)->flush();
            $this->app->make(Settings::class)->flush();
        });
    }

    /**
     * Every "module.resource.action" permission is answered by the
     * PermissionChecker. An optional first argument narrows the check to
     * one branch: Gate::allows('frontdesk.orders.create', $branch).
     */
    private function registerPermissionGate(): void
    {
        Gate::before(function ($user, string $ability, array $arguments = []): ?bool {
            if (! $user instanceof User || ! $this->app->make(PermissionRegistry::class)->has($ability)) {
                return null;
            }

            $branch = $arguments[0] ?? null;
            $branchId = $branch instanceof Branch ? $branch->id : (is_int($branch) ? $branch : null);

            return $this->app->make(PermissionChecker::class)->allows($user, $ability, $branchId);
        });
    }

    private function registerLoginHooks(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $clinics = $this->app->make(ClinicContext::class);

            if (! $event->user instanceof User || ! $clinics->has()) {
                return;
            }

            session()->put(EnsureSessionBelongsToClinic::SESSION_KEY, $clinics->id());

            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            $this->app->make(AuditLogger::class)->record('auth.login', $event->user);
        });

        Event::listen(Failed::class, function (Failed $event): void {
            if ($this->app->make(ClinicContext::class)->has()) {
                $this->app->make(AuditLogger::class)->record('auth.failed', null, [], [], [
                    'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 255),
                ]);
            }
        });
    }

    /**
     * A queued job remembers which clinic it was created for and connects
     * to that clinic's database before it runs.
     */
    private function makeQueuedJobsClinicAware(): void
    {
        Queue::createPayloadUsing(function (): array {
            $clinicId = $this->app->make(ClinicContext::class)->id();

            return $clinicId === null ? [] : ['clinic_id' => $clinicId];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $clinicId = $event->job->payload()['clinic_id'] ?? null;
            $manager = $this->app->make(ClinicManager::class);

            // A job run in-line during a request already has its clinic;
            // reconnecting there would break the request's open transaction.
            if ($clinicId === $this->app->make(ClinicContext::class)->id()) {
                return;
            }

            $clinicId === null ? $manager->deactivate() : $manager->activate(Clinic::findOrFail($clinicId));
        });

        $leaveClinic = function (JobProcessed|JobFailed $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(ClinicManager::class)->deactivate();
            }
        };

        Event::listen(JobProcessed::class, $leaveClinic);
        Event::listen(JobFailed::class, $leaveClinic);
    }

    private function bootModules(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        foreach (array_keys($registry->all()) as $key) {
            if (class_exists($registry->get($key)->entry)) {
                $registry->module($key)->boot();
            }
        }
    }
}
