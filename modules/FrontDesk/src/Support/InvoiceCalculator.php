<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use App\Core\Money\Money;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\LineKind;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Models\Invoice;

/**
 * Works out an invoice's totals and status from its lines, payments, and
 * approved refunds. This is the only place those figures are calculated.
 *
 *   total    = sum of all lines
 *   due      = total - paid + refunded
 *
 * A negative due means the patient has paid more than is now charged (a
 * test was cancelled after payment) and is owed a refund.
 */
final class InvoiceCalculator
{
    /**
     * @return array{subtotal: Money, discount_total: Money, adjustment_total: Money, total: Money, paid_total: Money, refunded_total: Money, due_amount: Money, status: InvoiceStatus}
     */
    public function compute(Invoice $invoice): array
    {
        $byKind = [LineKind::Charge->value => Money::zero(), LineKind::Discount->value => Money::zero(), LineKind::Adjustment->value => Money::zero()];

        foreach ($invoice->lines()->get() as $line) {
            $byKind[$line->kind->value] = $byKind[$line->kind->value]->plus($line->amount);
        }

        $total = Money::sum(...array_values($byKind));
        $paid = Money::sum(...$invoice->payments()->get()->map->amount->all());
        $refunded = Money::sum(...$invoice->refunds()->where('status', RefundStatus::Approved->value)->get()->map->amount->all());
        $netPaid = $paid->minus($refunded);
        $due = $total->minus($netPaid);

        $status = match (true) {
            $invoice->status === InvoiceStatus::Void => InvoiceStatus::Void,
            ! $total->isPositive() && $netPaid->isZero() => $refunded->isPositive() ? InvoiceStatus::Refunded : InvoiceStatus::Paid,
            $netPaid->isZero() => InvoiceStatus::Issued,
            $due->isPositive() => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Paid,
        };

        return [
            'subtotal' => $byKind[LineKind::Charge->value],
            'discount_total' => $byKind[LineKind::Discount->value]->negate(),
            'adjustment_total' => $byKind[LineKind::Adjustment->value],
            'total' => $total,
            'paid_total' => $paid,
            'refunded_total' => $refunded,
            // A void invoice is owed nothing, whatever its lines say.
            'due_amount' => $status === InvoiceStatus::Void ? Money::zero() : $due,
            'status' => $status,
        ];
    }

    /** Recalculate and store. Call with the invoice row locked. */
    public function refresh(Invoice $invoice): Invoice
    {
        $invoice->forceFill($this->compute($invoice))->save();

        return $invoice;
    }

    /**
     * Fields whose stored value differs from the calculated one.
     *
     * @return array<string, array{stored: string, calculated: string}>
     */
    public function differences(Invoice $invoice): array
    {
        $differences = [];

        foreach ($this->compute($invoice) as $field => $calculated) {
            $stored = $invoice->{$field};
            $a = $stored instanceof Money ? $stored->toDecimal() : $stored->value;
            $b = $calculated instanceof Money ? $calculated->toDecimal() : $calculated->value;

            if ($a !== $b) {
                $differences[$field] = ['stored' => $a, 'calculated' => $b];
            }
        }

        return $differences;
    }
}
