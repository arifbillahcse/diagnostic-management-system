<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\Payment;

/** Raised after the change has been committed. */
final class PaymentReceived
{
    public function __construct(public readonly Payment $payment) {}
}
