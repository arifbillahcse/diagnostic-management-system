<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Core\Money\Money;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\Licence;
use App\Platform\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Billing clinics: issuing an invoice for the next period, recording the
 * payment (which moves the clinic's paid-until date to the end of that
 * period), and voiding a wrong invoice. Platform users only; every step is
 * written to the platform audit log.
 *
 * An invoice is paid in full by one payment. A wrong invoice is voided and
 * issued again, never edited.
 */
final class Billing
{
    public function __construct(
        private readonly PlatformCounter $counter,
        private readonly Licence $licence,
    ) {}

    /** The period the next invoice covers: from the day after the clinic is covered until, or today. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function nextPeriod(Clinic $clinic, string $billingPeriod): array
    {
        $tz = $clinic->timezone ?: 'Asia/Dhaka';
        $today = CarbonImmutable::now($tz)->startOfDay();

        // Already invoiced and not void: continue after the last invoiced period.
        $last = SubscriptionInvoice::where('clinic_id', $clinic->id)->where('status', '!=', 'void')->max('period_end');
        $covered = $this->licence->endsOn($clinic);

        $from = collect([$last ? CarbonImmutable::parse($last, $tz)->addDay() : null, $covered?->startOfDay()->addDay(), $today])
            ->filter()->max();

        $to = $billingPeriod === 'yearly' ? $from->addYearNoOverflow()->subDay() : $from->addMonthNoOverflow()->subDay();

        return [$from, $to];
    }

    public function issue(Clinic $clinic, string $billingPeriod, ?string $amount = null, ?string $note = null): SubscriptionInvoice
    {
        $this->authorize();

        if (! in_array($billingPeriod, ['monthly', 'yearly'], true)) {
            throw new RuntimeException('Choose monthly or yearly.');
        }

        $plan = $clinic->plan ?? throw new RuntimeException('Give the clinic a plan first.');
        $money = $amount === null || trim($amount) === ''
            ? ($billingPeriod === 'yearly' ? $plan->price_yearly : $plan->price_monthly)
            : $this->money($amount);

        if (! $money->isPositive()) {
            throw new RuntimeException("The {$plan->name} plan has no {$billingPeriod} price yet. Set it under Plans, or type the amount.");
        }

        return DB::connection('control')->transaction(function () use ($clinic, $plan, $billingPeriod, $money, $note): SubscriptionInvoice {
            Clinic::whereKey($clinic->id)->lockForUpdate()->first();
            [$from, $to] = $this->nextPeriod($clinic, $billingPeriod);
            $year = CarbonImmutable::now($clinic->timezone ?: 'Asia/Dhaka')->format('y');

            $invoice = SubscriptionInvoice::create([
                'invoice_no' => sprintf('SUB-%s-%05d', $year, $this->counter->next('subscription_invoice', $year)),
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_period' => $billingPeriod,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'amount' => $money->toDecimal(),
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
                'status' => 'issued',
                'due_on' => $from->toDateString(),
                'issued_by' => Auth::guard('platform')->id(),
            ]);

            PlatformAudit::record('invoice.issued', $clinic->id, [
                'invoice_no' => $invoice->invoice_no, 'amount' => $money->toDecimal(), 'period' => "{$from->toDateString()} to {$to->toDateString()}",
            ]);

            return $invoice;
        });
    }

    /** Records the full payment; the clinic is then paid until the end of the invoice's period. */
    public function recordPayment(SubscriptionInvoice $invoice, string $method, string $receivedOn, ?string $reference = null): SubscriptionPayment
    {
        $this->authorize();

        if (! array_key_exists($method, SubscriptionPayment::METHODS)) {
            throw new RuntimeException('Choose how the money came in.');
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $receivedOn) ?: null;
        if (! $day || $day->format('Y-m-d') !== $receivedOn || $day->isAfter(CarbonImmutable::now()->addDay())) {
            throw new RuntimeException('Choose the day the money was received (not in the future).');
        }

        return DB::connection('control')->transaction(function () use ($invoice, $method, $receivedOn, $reference): SubscriptionPayment {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== 'issued') {
                throw new RuntimeException('This invoice is '.strtolower(SubscriptionInvoice::STATUSES[$invoice->status]).'.');
            }

            $payment = SubscriptionPayment::create([
                'invoice_id' => $invoice->id,
                'clinic_id' => $invoice->clinic_id,
                'amount' => $invoice->amount->toDecimal(),
                'method' => $method,
                'reference' => filled($reference) ? mb_substr(trim((string) $reference), 0, 100) : null,
                'received_on' => $receivedOn,
                'recorded_by' => Auth::guard('platform')->id(),
            ]);

            $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            $clinic = Clinic::whereKey($invoice->clinic_id)->lockForUpdate()->firstOrFail();
            $paidUntil = $clinic->paid_until && $clinic->paid_until->toDateString() > $invoice->period_end->toDateString()
                ? $clinic->paid_until->toDateString()
                : $invoice->period_end->toDateString();
            $this->licence->setDates($clinic, null, $paidUntil);

            PlatformAudit::record('invoice.paid', $invoice->clinic_id, [
                'invoice_no' => $invoice->invoice_no, 'amount' => $invoice->amount->toDecimal(), 'method' => $method,
                'reference' => $payment->reference, 'paid_until' => $paidUntil,
            ]);

            return $payment;
        });
    }

    public function void(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        $this->authorize();

        if (blank($reason)) {
            throw new RuntimeException('Give a reason; it is kept in the log.');
        }

        return DB::connection('control')->transaction(function () use ($invoice, $reason): SubscriptionInvoice {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== 'issued') {
                throw new RuntimeException('Only an unpaid invoice can be voided.');
            }

            $invoice->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => mb_substr(trim($reason), 0, 255)])->save();
            PlatformAudit::record('invoice.voided', $invoice->clinic_id, ['invoice_no' => $invoice->invoice_no, 'reason' => trim($reason)]);

            return $invoice;
        });
    }

    private function money(string $amount): Money
    {
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($amount))) {
            throw new RuntimeException('Type the amount like 3000 or 2999.50.');
        }

        return Money::fromDecimal(trim($amount));
    }

    private function authorize(): void
    {
        if (! Auth::guard('platform')->check()) {
            throw new RuntimeException('Only a signed-in platform user can do this.');
        }
    }
}
