<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Http;

use App\Core\Settings\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Permissions;

/**
 * Printable invoice and receipt pages. They open in a new tab and use the
 * browser's own print function.
 */
final class PrintController
{
    public function __construct(private readonly Settings $settings) {}

    public function invoice(Invoice $invoice): View
    {
        Gate::authorize(Permissions::ORDERS_VIEW, $invoice->branch_id);

        return view('frontdesk::print.invoice', [
            'clinic' => $this->clinic(),
            'invoice' => $invoice->load(['patient', 'lines', 'payments', 'refunds', 'order.referralDoctor', 'branch']),
        ]);
    }

    public function receipt(Payment $payment): View
    {
        Gate::authorize(Permissions::ORDERS_VIEW, $payment->branch_id);

        return view('frontdesk::print.receipt', [
            'clinic' => $this->clinic(),
            'payment' => $payment->load(['invoice.patient', 'receiver']),
        ]);
    }

    /** @return array<string, ?string> */
    private function clinic(): array
    {
        return [
            'name' => $this->settings->get('clinic.name'),
            'address' => $this->settings->get('clinic.address'),
            'phone' => $this->settings->get('clinic.phone'),
            'header' => $this->settings->get('clinic.report_header'),
        ];
    }
}
