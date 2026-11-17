<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Branches\Actions\SaveBranch;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleException;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Actions\ChangePlan;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\Plan;
use App\Core\Tenancy\PlanLimitException;
use App\Filament\Resources\Branches\Pages\ManageBranches;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class PlansTest extends TestCase
{
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinic = $this->useClinic('alpha');
        $this->actingAs($this->owner());
    }

    private function moveTo(string $key): array
    {
        $result = app(ChangePlan::class)->handle($this->clinic->fresh(), Plan::where('key', $key)->firstOrFail());
        app(ClinicContext::class)->set($this->clinic->fresh());

        return $result;
    }

    public function test_three_editions_sit_beside_the_full_trial_plan(): void
    {
        $plans = Plan::orderBy('sort_order')->get()->keyBy('key');

        $this->assertSame(['full', 'basic', 'standard', 'premium'], $plans->keys()->all());
        $this->assertSame(['frontdesk', 'laboratory', 'reports'], $plans['basic']->moduleKeys());
        $this->assertSame(1, $plans['basic']->max_branches);
        $this->assertTrue($plans['premium']->includesModule('analyzers'));
        $this->assertFalse($plans['standard']->includesModule('people'));
        $this->assertSame('full', $this->clinic->plan->key, 'New and existing clinics stay on the full plan');
        $this->assertNull($plans['full']->max_branches);
    }

    public function test_moving_down_switches_off_modules_outside_the_plan_and_keeps_their_data(): void
    {
        $manager = app(ModuleManager::class);
        foreach (['finance', 'owner', 'messaging', 'inventory'] as $key) {
            $manager->enable($key);
        }
        DB::connection('clinic')->table('inv_suppliers')->insert(['public_id' => '01TESTSUPPLIER0000000000AA', 'name' => 'Kept supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->moveTo('basic');

        $this->assertEqualsCanonicalizing(['finance', 'owner', 'messaging', 'inventory'], $result['switched_off']);
        $state = app(ModuleState::class);
        $state->flush();
        $this->assertFalse($state->isEnabled('inventory'));
        $this->assertTrue($state->isEnabled('laboratory'));
        $this->assertSame(1, DB::connection('clinic')->table('inv_suppliers')->count(), 'Data stays');

        try {
            $manager->enable('inventory');
            $this->fail('A module outside the plan was switched on');
        } catch (ModuleException) {
            $this->addToAssertionCount(1);
        }

        $this->moveTo('standard');
        $manager->enable('inventory');
        $this->assertSame('Kept supplier', DB::connection('clinic')->table('inv_suppliers')->value('name'), 'It comes back with its data');
        $this->assertTrue(DB::connection('control')->table('provisioning_log')->where('step', 'plan_changed')->exists());
    }

    public function test_the_branch_limit_stops_new_and_reactivated_branches(): void
    {
        $this->moveTo('basic'); // one branch

        try {
            app(SaveBranch::class)->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]);
            $this->fail('A second branch was added on a one-branch plan');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Basic', $e->getMessage());
        }

        $closed = app(SaveBranch::class)->create(['code' => 'OLD', 'name' => 'Old branch', 'is_active' => false]);

        $this->expectException(PlanLimitException::class);
        app(SaveBranch::class)->update($closed, ['is_active' => true]);
    }

    public function test_the_branches_screen_shows_the_limit_and_keeps_the_form_open(): void
    {
        $this->moveTo('basic');

        Livewire::test(ManageBranches::class)
            ->callAction('create', ['code' => 'B2', 'name' => 'Second', 'is_active' => true])
            ->assertNotified('Not allowed on your plan');
        $this->assertSame(1, Branch::count());

        $this->moveTo('premium');
        Livewire::test(ManageBranches::class)
            ->callAction('create', ['code' => 'B2', 'name' => 'Second', 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(2, Branch::count());
    }

    public function test_plans_are_set_and_clinics_moved_from_the_command_line(): void
    {
        $this->artisan('plans:set', ['key' => 'standard', '--monthly' => '3000', '--branches' => '2'])->assertSuccessful();
        $standard = Plan::where('key', 'standard')->first();
        $this->assertSame('3000.00', $standard->price_monthly->toDecimal());
        $this->assertSame('30000.00', $standard->price_yearly->toDecimal(), 'Yearly is ten months');
        $this->assertSame(2, $standard->max_branches);

        $this->artisan('plans:set', ['key' => 'standard', '--modules' => 'frontdesk,nonsense'])->assertFailed();
        $this->artisan('plans:list')->expectsOutputToContain('standard')->assertSuccessful();

        $this->artisan('clinic:plan', ['clinic' => $this->clinic->id, 'plan' => 'basic', '--force' => true])
            ->expectsOutputToContain('is now on the Basic plan')->assertSuccessful();
        $this->assertSame('basic', $this->clinic->fresh()->plan->key);
    }

    public function test_the_modules_page_names_the_plan(): void
    {
        $this->moveTo('standard');
        $this->signIn($this->owner())->get('http://alpha.test/modules')->assertOk()->assertSee('Your plan:')->assertSee('Standard');
    }
}
