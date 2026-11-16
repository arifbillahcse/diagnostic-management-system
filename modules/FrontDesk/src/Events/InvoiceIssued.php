<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\Invoice;

/** Raised after the change has been committed. */
final class InvoiceIssued
{
    public function __construct(public readonly Invoice $invoice) {}
}
