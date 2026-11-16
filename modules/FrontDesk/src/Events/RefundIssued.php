<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\Refund;

/** Raised after the change has been committed. */
final class RefundIssued
{
    public function __construct(public readonly Refund $refund) {}
}
