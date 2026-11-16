<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Events\PaymentReceived;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\CashDrawer;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\FrontDesk\Support\InvoiceCalculator;

/**
 * Records money received against an invoice.
 *
 * Pass an idempotency key when the same request might arrive twice (a
 * double click, a payment gateway retry): the second call returns the first
 * payment instead of taking the money again.
 */
final class RecordPayment
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly InvoiceCalculator $calculator,
        private readonly CashDrawer $drawer,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Invoice $invoice, Money $amount, PaymentMethod $method, ?string $reference = null, ?string $idempotencyKey = null): Payment
    {
        Gate::authorize(Permissions::PAYMENTS_RECORD, $invoice->branch_id);

        /** @var User $user */
        $user = Auth::user();

        if ($idempotencyKey !== null && ($existing = $this->existing($idempotencyKey, $invoice, $amount)) !== null) {
            return $existing;
        }

        try {
            $payment = DB::connection('clinic')->transaction(function () use ($invoice, $amount, $method, $reference, $idempotencyKey, $user): Payment {
                // Lock the invoice so two payments cannot both be checked
                // against the same due amount.
                $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if ($invoice->isVoid()) {
                    throw new FrontDeskException('This invoice is void.');
                }

                if (! $amount->isPositive()) {
                    throw new FrontDeskException('The payment amount must be more than zero.');
                }

                if ($amount->greaterThan($invoice->due_amount)) {
                    throw new FrontDeskException("The payment is more than the amount due ({$invoice->due_amount}).");
                }

                $session = $method === PaymentMethod::Cash ? $this->drawer->requireOpen($user, $invoice->branch_id) : null;
                $now = now();

                $payment = Payment::create([
                    'receipt_no' => $this->numbers->generate('receipt', $invoice->branch),
                    'invoice_id' => $invoice->id,
                    'branch_id' => $invoice->branch_id,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => filled($reference) ? trim($reference) : null,
                    'received_by' => $user->id,
                    'cash_session_id' => $session?->id,
                    'received_at' => $now,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => $now,
                ]);

                $this->calculator->refresh($invoice);

                $this->audit->record('payment.recorded', $payment, [], [
                    'receipt_no' => $payment->receipt_no,
                    'invoice_no' => $invoice->invoice_no,
                    'amount' => $amount->toDecimal(),
                    'method' => $method->value,
                    'due_after' => $invoice->due_amount->toDecimal(),
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same key arrived twice at the same moment; the other
            // request won. Answer with its payment.
            if ($idempotencyKey !== null && ($existing = $this->existing($idempotencyKey, $invoice, $amount)) !== null) {
                return $existing;
            }

            throw $e;
        }

        event(new PaymentReceived($payment));

        return $payment;
    }

    private function existing(string $key, Invoice $invoice, Money $amount): ?Payment
    {
        $payment = Payment::where('idempotency_key', $key)->first();

        if ($payment !== null && ($payment->invoice_id !== $invoice->id || ! $payment->amount->equals($amount))) {
            throw new FrontDeskException('This payment request was already used for a different payment.');
        }

        return $payment;
    }
}
