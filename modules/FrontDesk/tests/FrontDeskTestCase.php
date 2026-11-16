<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Patients\Patient;
use Modules\FrontDesk\Actions\ManageCashSession;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Service;
use Tests\TestCase;

/**
 * Shared set-up for front desk tests: a clinic with the module on (it is on
 * by default) and helpers to build the usual records.
 */
abstract class FrontDeskTestCase extends TestCase
{
    protected Branch $branch;

    protected User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useClinic('alpha');
        $this->branch = Branch::firstOrFail();
        $this->ownerUser = $this->owner();
        $this->actingAs($this->ownerUser);
    }

    protected function staff(string ...$roles): User
    {
        $user = $this->userWithRoles(...$roles);
        $user->forceFill(['default_branch_id' => $this->branch->id])->save();

        return $user;
    }

    protected function service(string $name, string $price, ServiceType $type = ServiceType::LabTest): Service
    {
        return Service::create([
            'code' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 6)).random_int(100, 999),
            'name' => $name,
            'type' => $type,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    protected function package(string $name, string $price, Service ...$components): Service
    {
        $package = $this->service($name, $price, ServiceType::Package);
        $package->packageItems()->sync(array_map(fn (Service $s): int => $s->id, $components));

        return $package;
    }

    protected function patient(string $name = 'Rahim Uddin'): Patient
    {
        $patient = new Patient([
            'name' => $name,
            'sex' => 'male',
            'date_of_birth' => '1990-05-20',
            'phone' => '01711000000',
            'phone_normalized' => '8801711000000',
            'registered_branch_id' => $this->branch->id,
        ]);
        $patient->patient_code = 'P-T-'.str_pad((string) (Patient::count() + 1), 4, '0', STR_PAD_LEFT);
        $patient->save();

        return $patient;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function order(array $prices, array $options = []): Order
    {
        $services = [];

        foreach ($prices as $i => $price) {
            $services[] = $price instanceof Service ? $price : $this->service('Test '.chr(65 + $i).uniqid(), (string) $price);
        }

        return app(PlaceOrder::class)->handle(
            $this->patient(),
            $this->branch,
            array_map(fn (Service $s): int => $s->id, $services),
            $options,
        );
    }

    protected function invoice(Order $order): Invoice
    {
        return Invoice::where('order_id', $order->id)->firstOrFail();
    }

    protected function openCash(string $float = '0'): CashSession
    {
        return app(ManageCashSession::class)->open($this->branch, Money::fromDecimal($float));
    }

    protected function pay(Order|Invoice $target, string $amount, PaymentMethod $method = PaymentMethod::Bkash, ?string $key = null): Payment
    {
        $invoice = $target instanceof Order ? $this->invoice($target) : $target;

        return app(RecordPayment::class)->handle($invoice, Money::fromDecimal($amount), $method, null, $key);
    }

    /** Assert the stored totals of an invoice, as decimal strings. */
    protected function assertInvoice(Order|Invoice $target, string $total, string $paid, string $refunded, string $due, string $status): void
    {
        $invoice = ($target instanceof Order ? $this->invoice($target) : $target)->fresh();

        $this->assertSame(
            compact('total', 'paid', 'refunded', 'due', 'status'),
            [
                'total' => $invoice->total->toDecimal(),
                'paid' => $invoice->paid_total->toDecimal(),
                'refunded' => $invoice->refunded_total->toDecimal(),
                'due' => $invoice->due_amount->toDecimal(),
                'status' => $invoice->status->value,
            ],
        );
    }
}
