<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Console;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Console\SampleDataCommand as Base;
use App\Core\Money\Money;
use App\Core\Patients\Patient;
use App\Core\Tenancy\Clinic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Actions\DecideRefund;
use Modules\FrontDesk\Actions\ManageCashSession;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Support\CashDrawer;
use RuntimeException;

/**
 * Sample data for the money screens that the order data does not reach:
 * a closed cash session that was short by a few taka, and refunds in each
 * state (approved, waiting for approval, rejected).
 *
 * Run it after laboratory:sample-data, which makes the receptionist, the
 * patients and the paid invoices these refunds are taken from.
 */
final class SampleDataCommand extends Base
{
    protected $signature = 'frontdesk:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load a closed cash session with a shortage and sample refunds, for trying the money screens';

    protected function moduleKey(): string
    {
        return 'frontdesk';
    }

    protected function load(Clinic $clinic): array
    {
        if (Refund::query()->exists()) {
            throw new RuntimeException('This clinic already has refunds. Sample data is only for a clinic without any.');
        }

        $owner = Auth::user();
        $receptionist = User::whereHas('roles', fn ($q) => $q->where('key', 'receptionist'))->first()
            ?? throw new RuntimeException('There is no receptionist account. Run laboratory:sample-data first.');
        $branch = Branch::orderBy('id')->firstOrFail();
        $cbc = Service::where('code', 'CBC')->firstOrFail();

        // Yesterday: the owner opens a drawer, takes cash for two bills, and
        // closes it Tk 50 short, with the reason written down.
        $yesterday = CarbonImmutable::now($clinic->timezone)->subDay()->startOfDay();
        $this->travelTo($yesterday->addHours(9));
        $session = app(ManageCashSession::class)->open($branch, Money::fromDecimal('500'));

        foreach (Patient::orderBy('id')->limit(2)->get() as $i => $patient) {
            $this->travelTo($yesterday->addHours(10 + $i));
            $order = app(PlaceOrder::class)->handle($patient, $branch, [$cbc->id]);
            $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
            app(RecordPayment::class)->handle($invoice, $invoice->total, PaymentMethod::Cash);
        }

        $this->travelTo($yesterday->addHours(19));
        $expected = app(CashDrawer::class)->expected($session);
        app(ManageCashSession::class)->close($session, $expected->minus(Money::fromDecimal('50')), 'Short by Tk 50: change given back twice by mistake.');
        $this->backToNow();

        // Refunds on paid mobile or card bills: the receptionist asks, the owner decides.
        $payments = Payment::where('method', '!=', PaymentMethod::Cash->value)
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Paid->value))
            ->orderBy('id')->limit(3)->get();

        if ($payments->count() < 3) {
            throw new RuntimeException('There are not enough paid mobile or card bills for sample refunds. Run laboratory:sample-data first.');
        }

        $request = function (Payment $payment, string $amount, string $reason) use ($receptionist, $owner): Refund {
            Auth::setUser($receptionist);
            $refund = app(RequestRefund::class)->handle($payment->invoice, Money::fromDecimal($amount), $payment->method, $reason, 'RFD-'.$payment->id);
            Auth::setUser($owner);

            return $refund;
        };

        $approved = $request($payments[0], '150', 'The patient cancelled one test before the sample was taken.');
        app(DecideRefund::class)->approve($approved, 'Checked with the lab: not started.');

        $request($payments[1], '200', 'Test done twice by mistake.'); // left waiting for approval

        $rejected = $request($payments[2], '100', 'The patient asked for a discount afterwards.');
        app(DecideRefund::class)->reject($rejected, 'Discounts are given at the counter, before payment.');

        return [
            '1 closed cash session, Tk 50 short, with a note (Cash sessions)',
            '3 refunds: 1 approved, 1 waiting for approval, 1 rejected (Refunds)',
        ];
    }
}
