<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Auth\User;
use App\Core\Patients\Patient;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Support\Facades\Hash;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Service;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\Result;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Tests\TestCase;

final class SampleDataTest extends TestCase
{
    public function test_it_loads_a_catalog_staff_patients_and_orders_at_every_stage(): void
    {
        $clinic = $this->createClinic('alpha');

        $this->artisan('laboratory:sample-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])
            ->expectsOutputToContain('Sample data loaded')
            ->assertSuccessful();

        $this->assertNull(auth()->user());
        $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 5, 'The clock was left moved.');

        $this->useExistingClinic($clinic);

        $this->assertSame(13, Service::count());
        $this->assertSame(30, Order::count());
        $this->assertSame(16, Patient::count());
        $this->assertSame(30, Order::distinct()->count('order_no'));

        $technician = User::where('email', "lab.technician@clinic{$clinic->id}.example")->firstOrFail();
        $this->assertTrue(Hash::check('sample-pass-123', $technician->password));

        // Money: paid, part-paid, and unpaid invoices, and stored totals that add up.
        foreach ([InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Issued] as $status) {
            $this->assertTrue(Invoice::where('status', $status)->exists(), "No invoice is {$status->value}.");
        }

        foreach (Invoice::all() as $invoice) {
            $paid = Payment::where('invoice_id', $invoice->id)->get()->reduce(fn ($sum, Payment $p) => $sum + $p->amount->minor, 0);
            $this->assertSame($paid, $invoice->paid_total->minor);
            $this->assertSame($invoice->total->minor - $paid, $invoice->due_amount->minor);
        }

        // Laboratory: every stage is present.
        foreach ([WorkStatus::AwaitingSample, WorkStatus::Ready, WorkStatus::Entered, WorkStatus::Released] as $status) {
            $this->assertTrue(WorkItem::where('status', $status)->exists(), "No work is {$status->value}.");
        }

        $this->assertTrue(Sample::where('status', SampleStatus::Pending)->exists());
        $this->assertTrue(Sample::where('status', SampleStatus::Collected)->exists());
        $this->assertTrue(Result::where('flag', Flag::Normal)->exists());
        $this->assertTrue(Result::whereIn('flag', [Flag::High, Flag::Low, Flag::Abnormal])->exists());

        // It went through the real actions, so the audit trail has it.
        $this->assertTrue(AuditLog::where('action', 'patient.registered')->exists());
        $this->assertNotNull(Order::min('ordered_at'));
        $this->assertTrue(Order::where('ordered_at', '<', now()->subDays(8))->exists());

        $this->artisan('frontdesk:check-invoices', ['--clinic' => $clinic->id])->assertSuccessful();
    }

    public function test_it_refuses_a_clinic_that_already_has_data_and_a_weak_password(): void
    {
        $clinic = $this->createClinic('alpha');

        $this->artisan('laboratory:sample-data', ['clinic' => $clinic->id, '--password' => 'short', '--force' => true])->assertFailed();
        $this->artisan('laboratory:sample-data', ['clinic' => 999, '--force' => true])->assertFailed();

        $this->artisan('laboratory:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertSuccessful();
        $this->artisan('laboratory:sample-data', ['clinic' => $clinic->id, '--force' => true])
            ->expectsOutputToContain('already has services or orders')
            ->assertFailed();

        $this->useExistingClinic($clinic);
        $this->assertSame(30, Order::count());
    }

    private function useExistingClinic($clinic): void
    {
        app(ClinicManager::class)->activate($clinic);
    }
}
