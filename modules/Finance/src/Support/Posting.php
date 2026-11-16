<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Refund;

/**
 * Posts front desk money into the accounts: a payment in, a refund out.
 * Used both as events happen and by the catch-up command.
 */
final class Posting
{
    public function __construct(private readonly Ledger $ledger) {}

    public function payment(Payment $payment): void
    {
        $this->ledger->post(
            $this->ledger->accountFor($payment->method),
            $payment->amount,
            'payment',
            $payment->id,
            "Receipt {$payment->receipt_no}",
            $payment->received_at,
        );
    }

    public function refund(Refund $refund): void
    {
        $this->ledger->post(
            $this->ledger->accountFor($refund->method),
            $refund->amount->negate(),
            'refund',
            $refund->id,
            "Refund {$refund->refund_no}",
            $refund->decided_at,
        );
    }
}
