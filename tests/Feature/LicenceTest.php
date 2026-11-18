<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth\CorePermissions;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Licence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class LicenceTest extends TestCase
{
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka'));
        $this->clinic = $this->useClinic('alpha');
        $this->actingAs($this->owner());
    }

    private function refresh(): Clinic
    {
        app(Licence::class)->refresh($this->clinic->fresh());
        $this->clinic = $this->clinic->fresh();
        app(ClinicContext::class)->set($this->clinic);

        return $this->clinic;
    }

    public function test_a_new_clinic_gets_a_thirty_day_trial(): void
    {
        $this->assertSame('2026-12-01', $this->clinic->trial_ends_at->timezone('Asia/Dhaka')->toDateString());
        $this->assertSame(ClinicStatus::Trial, $this->clinic->status);
        $this->assertSame(30, app(Licence::class)->daysLeft($this->clinic));
    }

    public function test_trial_then_overdue_then_read_only_then_active_when_paid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 23:00', 'Asia/Dhaka'));
        $this->assertSame(ClinicStatus::Trial, $this->refresh()->status, 'The last day is still covered');

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Asia/Dhaka'));
        $this->assertSame(ClinicStatus::Overdue, $this->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-12-09 09:00', 'Asia/Dhaka'));
        $this->assertSame(ClinicStatus::ReadOnly, $this->refresh()->status);

        app(Licence::class)->setDates($this->clinic, null, '2027-01-08');
        $this->assertSame(ClinicStatus::Active, $this->clinic->fresh()->status);
        $this->assertSame(4, DB::connection('control')->table('provisioning_log')->where('clinic_id', $this->clinic->id)->whereIn('step', ['licence', 'licence_dates'])->count());
    }

    public function test_read_only_allows_looking_printing_and_export_but_no_change(): void
    {
        $this->clinic->forceFill(['status' => ClinicStatus::ReadOnly])->save();
        app(ClinicContext::class)->set($this->clinic->fresh());

        $this->assertTrue(Gate::allows(CorePermissions::PATIENTS_VIEW));
        $this->assertTrue(Gate::allows(CorePermissions::EXPORT_RUN));
        $this->assertTrue(Gate::allows('reports.reports.print'));
        $this->assertFalse(Gate::allows(CorePermissions::PATIENTS_CREATE), 'Even the owner cannot change anything');
        $this->assertFalse(Gate::allows(CorePermissions::SETTINGS_MANAGE));
        $this->assertFalse(Gate::allows('frontdesk.orders.create'));

        $this->signIn($this->owner())->get('http://alpha.test/')->assertOk()->assertSee('Read-only: the free trial ended');
    }

    public function test_a_clinic_without_dates_is_never_limited(): void
    {
        $this->clinic->forceFill(['trial_ends_at' => null, 'status' => ClinicStatus::Active])->save();
        $this->travelTo(CarbonImmutable::parse('2030-01-01', 'Asia/Dhaka'));

        $this->assertSame(ClinicStatus::Active, $this->refresh()->status);
        $this->assertNull(app(Licence::class)->endsOn($this->clinic));
    }

    public function test_the_daily_check_moves_clinics_and_reminds_the_owner_once(): void
    {
        config(['mail.default' => 'array']);
        $sent = fn (): array => array_map(fn ($m) => $m->getOriginalMessage()->getSubject(), app('mailer')->getSymfonyTransport()->messages()->all());

        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'Asia/Dhaka')); // 5 days left
        $this->artisan('clinic:licence-check')->assertSuccessful();
        $this->artisan('clinic:licence-check')->assertSuccessful();
        $this->assertSame(['Alpha Diagnostics: your free trial ends on 01 Dec 2026'], $sent());

        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00', 'Asia/Dhaka'));
        $this->artisan('clinic:licence-check')->expectsOutputToContain('1 status change(s)')->assertSuccessful();
        $this->assertSame(ClinicStatus::ReadOnly, $this->clinic->fresh()->status);
        $this->assertContains('Alpha Diagnostics is now read-only', $sent());

        auth()->logout();
        $this->get('http://alpha.test/login')->assertOk(); // a read-only clinic is still served
    }

    public function test_the_owner_sees_the_trial_banner_and_dates_are_set_from_the_command_line(): void
    {
        $this->signIn($this->owner())->get('http://alpha.test/')->assertOk()->assertSee('Free trial until 01 Dec 2026');

        $this->artisan('clinic:licence', ['clinic' => $this->clinic->id, '--extend-trial' => 14])->assertSuccessful();
        $this->assertSame('2026-12-15', $this->clinic->fresh()->trial_ends_at->timezone('Asia/Dhaka')->toDateString());

        $this->artisan('clinic:licence', ['clinic' => $this->clinic->id, '--paid-until' => '31-12-2026'])->assertFailed();
        $this->artisan('clinic:licence', ['clinic' => $this->clinic->id, '--paid-until' => '2027-10-31'])
            ->expectsOutputToContain('Active')->assertSuccessful();
    }
}
