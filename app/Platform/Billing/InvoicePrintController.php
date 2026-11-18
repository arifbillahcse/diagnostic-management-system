<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Core\Auth\CorePermissions;
use App\Core\Money\AmountInWords;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * A printable subscription invoice. Platform users may print any; a clinic
 * owner (clinic settings permission) only their own clinic's.
 */
final class InvoicePrintController
{
    public function __invoke(SubscriptionInvoice $invoice): View
    {
        if (! Auth::guard('platform')->check()) {
            abort_unless(app(ClinicContext::class)->id() === $invoice->clinic_id, 404);
            Gate::authorize(CorePermissions::SETTINGS_MANAGE);
        }

        return view('platform.invoice', [
            'invoice' => $invoice->load(['clinic.domains', 'payments']),
            'seller' => config('dms.platform.billing'),
            'words' => AmountInWords::for($invoice->amount),
        ]);
    }
}
