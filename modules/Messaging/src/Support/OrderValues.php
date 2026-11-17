<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use App\Core\Tenancy\ClinicContext;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;

/**
 * The placeholder values that come from an order and its bill.
 */
final class OrderValues
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ClinicContext $clinics,
    ) {}

    /** @return array<string, string> */
    public function for(Order $order, ?Invoice $invoice = null): array
    {
        $invoice ??= Invoice::where('order_id', $order->id)->first();
        $timezone = $this->clinics->require()->timezone;

        return [
            ...$this->messenger->clinicValues(),
            'patient' => (string) $order->patient?->name,
            'bill_no' => (string) $invoice?->invoice_no,
            'total' => (string) $invoice?->total->formatted(),
            'paid' => (string) $invoice?->netPaid()->formatted(),
            'due' => $invoice !== null && $invoice->due_amount->isPositive() ? $invoice->due_amount->formatted() : '0.00',
            'delivery' => $order->delivery_at ? $order->delivery_at->timezone($timezone)->format('d M, h:i A') : '',
        ];
    }
}
