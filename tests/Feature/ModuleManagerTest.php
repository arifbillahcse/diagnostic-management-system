<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Modules\Actions\DisableModule;
use App\Core\Modules\Actions\EnableModule;
use App\Core\Modules\ModuleDisabled;
use App\Core\Modules\ModuleEnabled;
use App\Core\Modules\ModuleException;
use App\Core\Modules\ModuleListener;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\Plan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ModuleManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useModuleFixtures();
    }

    private function modules(): ModuleManager
    {
        return app(ModuleManager::class);
    }

    private function state(): ModuleState
    {
        return app(ModuleState::class);
    }

    public function test_a_new_clinic_has_only_default_on_modules(): void
    {
        $this->useClinic();

        $this->assertSame([], $this->state()->enabledKeys(), 'none of the fixture modules is on by default');
        $this->assertFalse(Schema::connection('clinic')->hasTable('alpha_things'));
    }

    public function test_enabling_a_module_creates_its_tables_and_permissions(): void
    {
        $this->useClinic();
        Event::fake([ModuleEnabled::class]);

        $this->modules()->enable('alpha');

        $this->assertTrue($this->state()->isEnabled('alpha'));
        $this->assertTrue(Schema::connection('clinic')->hasTable('alpha_things'));
        $this->assertTrue(DB::connection('clinic')->table('permissions')->where('key', 'alpha.things.view')->where('module_key', 'alpha')->exists());
        $this->assertFalse(DB::connection('clinic')->table('permissions')->where('module_key', 'beta')->exists());

        $entry = AuditLog::where('action', 'module.enabled')->sole();
        $this->assertSame('alpha', $entry->context['module']);
        $this->assertTrue($entry->context['first_install']);
        Event::assertDispatched(ModuleEnabled::class, fn (ModuleEnabled $e) => $e->key === 'alpha');
    }

    public function test_a_module_needs_its_requirements_enabled_first(): void
    {
        $this->useClinic();

        try {
            $this->modules()->enable('gamma');
            $this->fail('Gamma needs Beta.');
        } catch (ModuleException $e) {
            $this->assertSame('Gamma needs these modules enabled first: Beta.', $e->getMessage());
        }

        $this->assertFalse($this->state()->isInstalled('gamma'));

        $this->modules()->enable('alpha');
        $this->modules()->enable('beta');
        $this->modules()->enable('gamma');

        $this->assertSame(['alpha', 'beta', 'gamma'], $this->state()->enabledKeys());
    }

    public function test_a_module_in_use_by_another_cannot_be_disabled(): void
    {
        $this->useClinic();
        $this->modules()->enable('alpha');
        $this->modules()->enable('beta');

        try {
            $this->modules()->disable('alpha');
            $this->fail('Alpha is required by Beta.');
        } catch (ModuleException $e) {
            $this->assertSame('Alpha cannot be disabled while these modules use it: Beta.', $e->getMessage());
        }

        $this->assertTrue($this->state()->isEnabled('alpha'));

        $this->modules()->disable('beta');
        $this->modules()->disable('alpha');

        $this->assertSame([], $this->state()->enabledKeys());
    }

    public function test_disabling_keeps_the_data_and_enabling_again_restores_it(): void
    {
        $this->useClinic();
        Event::fake([ModuleDisabled::class]);
        $this->modules()->enable('alpha');
        DB::connection('clinic')->table('alpha_things')->insert(['name' => 'kept']);

        $this->modules()->disable('alpha');

        $this->assertFalse($this->state()->isEnabled('alpha'));
        $this->assertTrue($this->state()->isInstalled('alpha'));
        $this->assertSame('kept', DB::connection('clinic')->table('alpha_things')->value('name'));
        $this->assertSame(1, AuditLog::where('action', 'module.disabled')->count());
        Event::assertDispatched(ModuleDisabled::class);

        $this->modules()->enable('alpha');

        $this->assertTrue($this->state()->isEnabled('alpha'));
        $this->assertSame(1, DB::connection('clinic')->table('alpha_things')->count());
        $this->assertFalse(AuditLog::where('action', 'module.enabled')->latest('id')->first()->context['first_install']);
        $this->assertSame(1, DB::connection('clinic')->table('modules')->where('module_key', 'alpha')->count());
    }

    public function test_enabling_or_disabling_twice_changes_nothing(): void
    {
        $this->useClinic();

        $this->modules()->enable('alpha');
        $this->modules()->enable('alpha');
        $this->assertSame(1, AuditLog::where('action', 'module.enabled')->count());

        $this->modules()->disable('alpha');
        $this->modules()->disable('alpha');
        $this->assertSame(1, AuditLog::where('action', 'module.disabled')->count());
    }

    public function test_a_module_outside_the_plan_cannot_be_enabled(): void
    {
        $plan = Plan::create(['key' => 'starter', 'name' => 'Starter']);
        $plan->setModuleKeys(['alpha']);
        $clinic = $this->useClinic('alpha', 'starter');

        $this->modules()->enable('alpha');

        try {
            $this->modules()->enable('beta');
            $this->fail('Beta is not in the Starter plan.');
        } catch (ModuleException $e) {
            $this->assertSame("Beta is not included in this clinic's plan.", $e->getMessage());
        }

        // An add-on grants it; an expired add-on does not.
        DB::connection('control')->table('clinic_modules')->insert([
            'clinic_id' => $clinic->id, 'module_key' => 'beta', 'source' => 'trial', 'expires_at' => now()->subDay(),
        ]);
        app(ClinicManager::class)->activate($clinic);
        $this->assertFalse(collect($this->modules()->overview())->firstWhere('manifest.key', 'beta')['entitled']);

        DB::connection('control')->table('clinic_modules')->update(['expires_at' => now()->addMonth()]);
        app(ClinicManager::class)->activate($clinic);
        $this->modules()->enable('beta');

        $this->assertTrue($this->state()->isEnabled('beta'));
    }

    public function test_module_state_is_separate_for_each_clinic(): void
    {
        $alpha = $this->useClinic('alpha');
        $this->modules()->enable('alpha');

        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($beta);

        $this->assertFalse($this->state()->isEnabled('alpha'));
        $this->assertFalse(Schema::connection('clinic')->hasTable('alpha_things'));

        app(ClinicManager::class)->activate($alpha);
        $this->assertTrue($this->state()->isEnabled('alpha'));
    }

    public function test_switching_modules_from_the_screen_needs_the_permission(): void
    {
        $this->useClinic();

        $this->actingAs($this->userWithRoles('receptionist'));
        try {
            app(EnableModule::class)->handle('alpha');
            $this->fail('A receptionist may not switch modules.');
        } catch (AuthorizationException) {
            $this->assertFalse($this->state()->isEnabled('alpha'));
        }

        $this->actingAs($this->owner());
        app(EnableModule::class)->handle('alpha');
        $this->assertTrue($this->state()->isEnabled('alpha'));

        $this->assertSame($this->owner()->id, AuditLog::where('action', 'module.enabled')->sole()->actor_id);

        $this->actingAs($this->userWithRoles('accountant'));
        $this->expectException(AuthorizationException::class);
        app(DisableModule::class)->handle('alpha');
    }

    public function test_the_overview_explains_why_a_switch_would_be_refused(): void
    {
        $this->useClinic();
        $this->modules()->enable('alpha');
        $this->modules()->enable('beta');

        $rows = collect($this->modules()->overview())->keyBy('manifest.key');

        $this->assertSame(['alpha', 'beta', 'gamma'], $rows->keys()->all());
        $this->assertSame('enabled', $rows['alpha']['status']);
        $this->assertSame(['Beta'], $rows['alpha']['dependents']);
        $this->assertSame('not_installed', $rows['gamma']['status']);
        $this->assertSame([], $rows['gamma']['missing']);
        $this->assertTrue($rows['gamma']['entitled']);

        $this->modules()->disable('beta');
        $rows = collect($this->modules()->overview())->keyBy('manifest.key');
        $this->assertSame('disabled', $rows['beta']['status']);
        $this->assertSame(['Beta'], $rows['gamma']['missing']);
    }

    public function test_module_routes_answer_not_found_while_the_module_is_disabled(): void
    {
        Route::middleware(['web', 'module:alpha'])->get('/alpha-things', fn () => 'alpha is on');
        $this->useClinic();

        $this->get('http://alpha.test/alpha-things')->assertNotFound();

        $this->modules()->enable('alpha');

        $this->get('http://alpha.test/alpha-things')->assertOk()->assertSee('alpha is on');
    }

    public function test_module_listeners_stay_silent_while_the_module_is_disabled(): void
    {
        $this->useClinic();

        $listener = new class extends ModuleListener
        {
            public int $handled = 0;

            protected function moduleKey(): string
            {
                return 'alpha';
            }

            protected function process(object $event): void
            {
                $this->handled++;
            }
        };

        $listener->handle(new \stdClass);
        $this->assertSame(0, $listener->handled);

        $this->modules()->enable('alpha');
        $listener->handle(new \stdClass);
        $this->assertSame(1, $listener->handled);

        $this->modules()->disable('alpha');
        $listener->handle(new \stdClass);
        $this->assertSame(1, $listener->handled);
    }
}
