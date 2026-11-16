<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;

/**
 * Asks for money to be given back. Nothing moves until the refund is
 * approved. When the clinic has switched the approval rule off and the
 * requester may approve, the refund is approved in the same step.
 */
final class RequestRefund
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
        private readonly DecideRefund $decide,
    ) {}

    public function handle(Invoice $invoice, Money $amount, PaymentMethod $method, string $reason, ?string $reference = null): Refund
    {
        Gate::authorize(Permissions::REFUNDS_REQUEST, $invoice->branch_id);

        if (blank($reason)) {
            throw new FrontDeskException('A reason is required for a refund.');
        }

        $refund = DB::connection('clinic')->transaction(function () use ($invoice, $amount, $method, $reason, $reference): Refund {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $amount->isPositive()) {
                throw new FrontDeskException('The refund amount must be more than zero.');
            }

            // Refunds already waiting count too, so two requests cannot
            // together ask for more than was paid.
            $pending = MoneyCast::fromDatabase($invoice->refunds()->where('status', RefundStatus::Pending->value)->sum('amount'));
            $available = $invoice->netPaid()->minus($pending);

            if ($amount->greaterThan($available)) {
                throw new FrontDeskException("Only {$available} can be refunded on this invoice.");
            }

            $refund = Refund::create([
                'invoice_id' => $invoice->id,
                'branch_id' => $invoice->branch_id,
                'amount' => $amount,
                'method' => $method,
                'reference' => filled($reference) ? trim($reference) : null,
                'reason' => trim($reason),
                'status' => RefundStatus::Pending,
                'requested_by' => Auth::id(),
                'requested_at' => now(),
            ]);

            $this->audit->record('refund.requested', $refund, [], [
                'invoice_no' => $invoice->invoice_no,
                'amount' => $amount->toDecimal(),
                'method' => $method->value,
            ], ['reason' => $refund->reason]);

            return $refund;
        });

        $needsSecondPerson = (bool) $this->settings->get(Permissions::SETTING_REFUND_APPROVAL, true);

        if (! $needsSecondPerson && Gate::allows(Permissions::REFUNDS_APPROVE, $invoice->branch_id)) {
            return $this->decide->approve($refund);
        }

        return $refund;
    }
}
