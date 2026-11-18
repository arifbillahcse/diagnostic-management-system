<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Modules\ModuleManager;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Plan;
use App\Platform\Actions\ManageClinic;
use App\Platform\Filament\Resources\Clinics\ListClinics;
use App\Platform\Filament\Resources\Clinics\ViewClinic;
use App\Platform\Filament\Resources\Plans\ListPlans;
use App\Platform\PlatformUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class PlatformConsoleTest extends TestCase
{
    private const CONSOLE = 'http://platform.test';

    private Clinic $alpha;

    private PlatformUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->createClinic('alpha');
        app(ClinicManager::class)->deactivate();
        $this->staff = PlatformUser::create(['name' => 'Arif', 'email' => 'arif@softorio.test', 'password' => 'a-long-platform-password']);
        $this->staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save(); // two-step set up
    }

    private function asPlatform(): void
    {
        $this->actingAs($this->staff, 'platform');
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    public function test_the_console_answers_only_on_its_domain_and_only_for_platform_users(): void
    {
        $this->get(self::CONSOLE.'/login')->assertOk()->assertSee('Softorio platform');
        $this->get(self::CONSOLE.'/clinics')->assertRedirect(self::CONSOLE.'/login');

        // A clinic owner's session is not a platform session.
        $clinicOwner = app(ClinicManager::class)->run($this->alpha, fn () => $this->owner());
        $this->actingAs($clinicOwner)->get(self::CONSOLE.'/clinics')->assertRedirect(self::CONSOLE.'/login');

        $this->actingAs($this->staff, 'platform')->get(self::CONSOLE.'/clinics')->assertOk()->assertSee('Alpha Diagnostics');

        // Clinic addresses still go to the clinic panel.
        auth()->guard('web')->logout();
        auth()->guard('platform')->logout();
        $this->flushSession();
        $this->get('http://alpha.test/login')->assertOk()->assertDontSee('Softorio platform');
    }

    public function test_two_step_sign_in_is_required(): void
    {
        $this->staff->forceFill(['app_authentication_secret' => null])->save();

        $response = $this->actingAs($this->staff, 'platform')->get(self::CONSOLE.'/clinics');
        $this->assertTrue($response->isRedirect(), 'Without two-step sign-in the console sends the user to set it up');
        $this->assertStringNotContainsString('/clinics', (string) $response->headers->get('Location'));
    }

    public function test_a_switched_off_platform_user_cannot_get_in(): void
    {
        $this->staff->forceFill(['is_active' => false])->save();

        $this->actingAs($this->staff, 'platform')->get(self::CONSOLE.'/clinics')->assertForbidden();
    }

    public function test_plan_dates_suspend_and_reactivate_are_audited(): void
    {
        $this->asPlatform();
        $manage = app(ManageClinic::class);

        app(ClinicManager::class)->run($this->alpha, fn () => app(ModuleManager::class)->enable('finance'));
        $result = $manage->changePlan($this->alpha, Plan::where('key', 'basic')->firstOrFail());
        $this->assertSame(['finance'], $result['switched_off']);

        $manage->extendTrial($this->alpha->fresh(), 10);
        $manage->setDates($this->alpha->fresh(), null, now()->addYear()->toDateString());
        $this->assertSame(ClinicStatus::Active, $this->alpha->fresh()->status);

        $manage->suspend($this->alpha->fresh(), 'Asked to stop');
        $this->assertSame(ClinicStatus::Suspended, $this->alpha->fresh()->status);
        $this->get('http://alpha.test/login')->assertForbidden();

        $manage->reactivate($this->alpha->fresh(), 'Resolved');
        $this->assertSame(ClinicStatus::Active, $this->alpha->fresh()->status);

        $actions = DB::connection('control')->table('platform_audit')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['clinic.plan_changed', 'clinic.dates_set', 'clinic.dates_set', 'clinic.status_changed', 'clinic.status_changed'], $actions);
        $this->assertSame($this->staff->id, DB::connection('control')->table('platform_audit')->value('platform_user_id'));

        $this->expectException(RuntimeException::class);
        $manage->suspend($this->alpha->fresh(), '');
    }

    public function test_nothing_runs_without_a_platform_user(): void
    {
        $this->expectExceptionMessage('Only a signed-in platform user');
        app(ManageClinic::class)->extendTrial($this->alpha, 5);
    }

    public function test_the_clinic_page_shows_usage_history_and_runs_its_actions(): void
    {
        $this->asPlatform();
        $this->get(self::CONSOLE.'/clinics/'.$this->alpha->getRouteKey())->assertOk()
            ->assertSee('Subscription')->assertSee('Active users')->assertSee('alpha.test');

        Livewire::test(ViewClinic::class, ['record' => $this->alpha->getRouteKey()])
            ->callAction('extend', ['days' => 7])->assertNotified('Trial extended')
            ->callAction('plan', ['plan_id' => Plan::where('key', 'premium')->value('id')])->assertNotified('Plan changed')
            ->callAction('dates', ['trial_ends' => '2020-01-31', 'paid_until' => 'not a date'])->assertHasActionErrors();
        $this->assertSame('premium', $this->alpha->fresh()->plan->key);

        Livewire::test(ListClinics::class)->assertCanSeeTableRecords([$this->alpha]);
    }

    public function test_plans_are_edited_in_the_console(): void
    {
        $this->asPlatform();
        $standard = Plan::where('key', 'standard')->firstOrFail();

        Livewire::test(ListPlans::class)
            ->callTableAction('edit', $standard, [
                'name' => 'Standard', 'description' => 'Most clinics', 'price_monthly' => '3000', 'price_yearly' => '30000',
                'max_branches' => 2, 'modules' => ['frontdesk', 'laboratory', 'reports', 'finance'], 'is_active' => true,
            ])->assertNotified('Plan saved');

        $standard->refresh();
        $this->assertSame('3000.00', $standard->price_monthly->toDecimal());
        $this->assertEqualsCanonicalizing(['frontdesk', 'laboratory', 'reports', 'finance'], $standard->moduleKeys());
        $this->assertTrue(DB::connection('control')->table('platform_audit')->where('action', 'plan.saved')->exists());
    }

    public function test_platform_users_are_added_from_the_command_line(): void
    {
        $this->artisan('platform:user', ['email' => 'new@softorio.test', 'name' => 'New', '--password' => 'short'])->assertFailed();
        $this->artisan('platform:user', ['email' => 'new@softorio.test', 'name' => 'New', '--password' => 'long-enough-password'])->assertSuccessful();
        $this->artisan('platform:user', ['email' => 'new@softorio.test', 'name' => 'New', '--password' => 'long-enough-password'])->assertFailed();
        $this->assertTrue(PlatformUser::where('email', 'new@softorio.test')->exists());
    }
}
