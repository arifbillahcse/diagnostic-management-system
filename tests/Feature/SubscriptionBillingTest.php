<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Licence;
use App\Core\Tenancy\Plan;
use App\Platform\Billing\Billing;
use App\Platform\Billing\SubscriptionInvoice;
use App\Platform\Billing\SubscriptionPayment;
use App\Platform\Filament\Resources\Clinics\ViewClinic;
use App\Platform\Filament\Resources\Invoices\ListInvoices;
use App\Platform\PlatformUser;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class SubscriptionBillingTest extends TestCase
{
    private const CONSOLE = 'http://platform.test';

    private Clinic $alpha;

    private PlatformUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka')); // trial ends 01 Dec 2026
        $this->alpha = $this->createClinic('alpha');
        app(ClinicManager::class)->deactivate();
        Plan::where('key', 'standard')->firstOrFail()->forceFill(['price_monthly' => '3000.00', 'price_yearly' => '30000.00'])->save();
        $this->alpha->forceFill(['plan_id' => Plan::where('key', 'standard')->value('id')])->save();
        $this->alpha = $this->alpha->fresh();

        $this->staff = PlatformUser::create(['name' => 'Arif', 'email' => 'arif@softorio.test', 'password' => 'a-long-platform-password']);
        $this->staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($this->staff, 'platform');
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    public function test_the_first_invoice_starts_after_the_trial_and_numbers_follow_the_counter(): void
    {
        $first = app(Billing::class)->issue($this->alpha, 'monthly');
        $this->assertSame('SUB-26-00001', $first->invoice_no);
        $this->assertSame('2026-12-02', $first->period_start->toDateString(), 'The day after the trial ends');
        $this->assertSame('2027-01-01', $first->period_end->toDateString());
        $this->assertSame('3000.00', $first->amount->toDecimal());
        $this->assertSame('Standard', $first->plan_name);

        $second = app(Billing::class)->issue($this->alpha->fresh(), 'yearly', '27000', 'Launch discount');
        $this->assertSame('SUB-26-00002', $second->invoice_no);
        $this->assertSame('2027-01-02', $second->period_start->toDateString(), 'It continues after the last invoice');
        $this->assertSame('2028-01-01', $second->period_end->toDateString());
        $this->assertSame('27000.00', $second->amount->toDecimal());
    }

    public function test_a_payment_moves_paid_until_and_makes_a_read_only_clinic_active_again(): void
    {
        $invoice = app(Billing::class)->issue($this->alpha, 'monthly');
        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00', 'Asia/Dhaka'));
        app(Licence::class)->refresh($this->alpha->fresh());
        $this->assertSame(ClinicStatus::ReadOnly, $this->alpha->fresh()->status);

        app(Billing::class)->recordPayment($invoice, 'bkash', '2026-12-20', 'TX123ABC');

        $clinic = $this->alpha->fresh();
        $this->assertSame('2027-01-01', $clinic->paid_until->toDateString());
        $this->assertSame(ClinicStatus::Active, $clinic->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('3000.00', SubscriptionPayment::sole()->amount->toDecimal());
        $this->assertSame(['invoice.issued', 'invoice.paid'], DB::connection('control')->table('platform_audit')->where('action', 'like', 'invoice.%')->orderBy('id')->pluck('action')->all());

        $this->expectExceptionMessage('This invoice is paid');
        app(Billing::class)->recordPayment($invoice, 'cash', '2026-12-20');
    }

    public function test_invoices_and_payments_are_never_changed_and_a_wrong_invoice_is_voided(): void
    {
        $invoice = app(Billing::class)->issue($this->alpha, 'monthly');

        try {
            $invoice->update(['amount' => '1.00']);
            $this->fail('An invoice amount was changed');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        app(Billing::class)->void($invoice, 'Wrong plan');
        $this->assertSame('void', $invoice->fresh()->status);
        $next = app(Billing::class)->issue($this->alpha->fresh(), 'monthly');
        $this->assertSame('2026-12-02', $next->period_start->toDateString(), 'A void invoice does not count');

        app(Billing::class)->recordPayment($next, 'nagad', '2026-11-01');
        foreach ([fn () => SubscriptionPayment::first()->update(['amount' => '1']), fn () => SubscriptionPayment::first()->delete(), fn () => $next->delete()] as $try) {
            try {
                $try();
                $this->fail('Billing records changed');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_billing_refuses_bad_input_and_anyone_but_platform_users(): void
    {
        foreach ([
            fn () => app(Billing::class)->issue($this->alpha, 'weekly'),
            fn () => app(Billing::class)->issue($this->alpha, 'monthly', '12.345'),
            fn () => app(Billing::class)->recordPayment(app(Billing::class)->issue($this->alpha->fresh(), 'monthly'), 'bitcoin', '2026-11-01'),
        ] as $try) {
            try {
                $try();
                $this->fail('Bad billing input accepted');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        auth()->guard('platform')->logout();
        $this->expectExceptionMessage('Only a signed-in platform user');
        app(Billing::class)->issue($this->alpha, 'monthly');
    }

    public function test_the_console_issues_records_and_prints(): void
    {
        Livewire::test(ViewClinic::class, ['record' => $this->alpha->getRouteKey()])
            ->callAction('invoice', ['billing_period' => 'monthly', 'amount' => '', 'note' => ''])
            ->assertNotified('Invoice issued');
        $invoice = SubscriptionInvoice::sole();

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->callTableAction('pay', $invoice, ['method' => 'bank', 'reference' => 'DBBL-77', 'received_on' => '2026-11-01'])
            ->assertNotified('Payment recorded');

        $this->get(self::CONSOLE.'/billing/invoices/'.$invoice->public_id)->assertOk()
            ->assertSee('SUB-26-00001')->assertSee('Alpha Diagnostics')->assertSee('3,000.00')->assertSee('PAID')->assertSee('DBBL-77');
    }

    public function test_the_owner_sees_their_subscription_and_prints_only_their_own_invoices(): void
    {
        $mine = app(Billing::class)->issue($this->alpha, 'monthly');
        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->deactivate();
        $beta->forceFill(['plan_id' => Plan::where('key', 'standard')->value('id')])->save();
        $theirs = app(Billing::class)->issue($beta->fresh(), 'monthly');
        auth()->guard('platform')->logout();
        auth()->shouldUse('web'); // back to the clinic sign-in

        app(ClinicManager::class)->activate($this->alpha->fresh());
        $owner = $this->owner();
        $this->signIn($owner)->get('http://alpha.test/subscription')->assertOk()
            ->assertSee('Standard')->assertSee($mine->invoice_no)->assertDontSee($theirs->invoice_no);
        $this->signIn($owner)->get('http://alpha.test/subscription/invoices/'.$mine->public_id)->assertOk()->assertSee('UNPAID');
        $this->signIn($owner)->get('http://alpha.test/subscription/invoices/'.$theirs->public_id)->assertNotFound();

        $receptionist = $this->userWithRoles('receptionist');
        $this->signIn($receptionist)->get('http://alpha.test/subscription/invoices/'.$mine->public_id)->assertForbidden();
    }
}
