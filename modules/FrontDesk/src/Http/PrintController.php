<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Http;

use App\Core\Documents\Letterhead;
use App\Core\Money\AmountInWords;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\FrontDesk\Permissions;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Printable invoice and receipt pages. They open in a new tab and use the
 * browser's own print function.
 */
final class PrintController
{
    public function __construct(
        private readonly Letterhead $letterhead,
        private readonly ClinicContext $clinics,
    ) {}

    /** The invoice on A5. "?copy=office" prints the clinic's own copy and "?copy=both" prints both, one per page. */
    public function invoice(Request $request, Invoice $invoice): View
    {
        Gate::authorize(Permissions::ORDERS_VIEW, $invoice->branch_id);

        $invoice->load(['patient', 'lines', 'payments.receiver', 'refunds', 'order.referralDoctor', 'order.creator', 'order.items.service.category', 'branch']);

        return view('frontdesk::print.invoice', [
            'clinic' => $this->letterhead->data(),
            'invoice' => $invoice,
            'tz' => $this->clinics->require()->timezone,
            'copies' => match ($request->query('copy')) {
                'office' => ['Office copy'],
                'both' => ['Patient copy', 'Office copy'],
                default => ['Patient copy'],
            },
            'autoprint' => $request->boolean('autoprint'),
            'barcode' => $this->barcode($invoice->invoice_no),
            'words' => AmountInWords::for($invoice->netPaid()),
            'rooms' => $this->rooms($invoice),
        ]);
    }

    public function receipt(Payment $payment): View
    {
        Gate::authorize(Permissions::ORDERS_VIEW, $payment->branch_id);

        return view('frontdesk::print.receipt', [
            'clinic' => $this->letterhead->data(),
            'payment' => $payment->load(['invoice.patient', 'receiver']),
        ]);
    }

    /**
     * Where the patient goes, in category order, each place once. Only
     * services still on the order count.
     *
     * @return list<string>
     */
    private function rooms(Invoice $invoice): array
    {
        $categories = $invoice->order->items
            ->filter(fn ($item): bool => $item->status->value === 'active' && $item->service?->category !== null)
            ->map(fn ($item): ServiceCategory => $item->service->category)
            ->unique('id')
            ->filter(fn (ServiceCategory $c): bool => filled($c->room))
            ->sortBy(['sort_order', 'name']);

        return $categories->pluck('room')->unique()->values()->all();
    }

    private function barcode(string $value): string
    {
        $svg = (new SvgRenderer)->render((new TypeCode128)->getBarcode($value), 220, 36);

        return preg_replace('/^<\?xml.*?\?>\s*(<!DOCTYPE.*?>\s*)?/s', '', $svg);
    }
}
