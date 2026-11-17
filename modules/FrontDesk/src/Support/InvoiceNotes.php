<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use Modules\FrontDesk\Models\Invoice;

/**
 * Short lines printed at the foot of an invoice, added by other modules
 * (for example where to see reports online). Each provider returns a line
 * or null.
 */
final class InvoiceNotes
{
    /** @var list<callable(Invoice): ?string> */
    private array $providers = [];

    /** @param callable(Invoice): ?string $provider */
    public function add(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<string> */
    public function for(Invoice $invoice): array
    {
        return array_values(array_filter(array_map(fn (callable $p): ?string => $p($invoice), $this->providers), 'filled'));
    }
}
