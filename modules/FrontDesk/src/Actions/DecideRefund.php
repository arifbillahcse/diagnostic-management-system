<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use App\Core\Numbering\NumberGenerator;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Events\RefundIssued;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\CashDrawer;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\FrontDesk\Support\InvoiceCalculator;

/**
 * Approves or rejects a refund request. Approval is the moment the money
 * leaves: the invoice totals change and, for cash, the approver's cash
 * session is charged.
 */
final class DecideRefund
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly InvoiceCalculator $calculator,
        private readonly CashDrawer $drawer,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    public function approve(Refund $refund, ?string $note = null): Refund
    {
        Gate::authorize(Permissions::REFUNDS_APPROVE, $refund->branch_id);

        /** @var User $user */
        $user = Auth::user();

        $refund = DB::connection('clinic')->transaction(function () use ($refund, $note, $user): Refund {
            $invoice = Invoice::whereKey($refund->invoice_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($refund);

            if ($this->settings->get(Permissions::SETTING_REFUND_APPROVAL, true) && $refund->requested_by === $user->id) {
                throw new FrontDeskException('A refund must be approved by a different person from the one who requested it.');
            }

            if ($refund->amount->greaterThan($invoice->netPaid())) {
                throw new FrontDeskException("Only {$invoice->netPaid()} can be refunded on this invoice now.");
            }

            $session = $refund->method === PaymentMethod::Cash ? $this->drawer->requireOpen($user, $refund->branch_id) : null;

            $refund->update([
                'refund_no' => $this->numbers->generate('refund', $invoice->branch),
                'status' => RefundStatus::Approved,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim($note) : null,
                'cash_session_id' => $session?->id,
            ]);

            $this->calculator->refresh($invoice);

            $this->audit->record('refund.approved', $refund, ['status' => RefundStatus::Pending->value], [
                'status' => RefundStatus::Approved->value,
                'refund_no' => $refund->refund_no,
                'invoice_no' => $invoice->invoice_no,
                'amount' => $refund->amount->toDecimal(),
                'method' => $refund->method->value,
            ]);

            return $refund;
        });

        event(new RefundIssued($refund));

        return $refund;
    }

    public function reject(Refund $refund, string $note): Refund
    {
        Gate::authorize(Permissions::REFUNDS_APPROVE, $refund->branch_id);

        if (blank($note)) {
            throw new FrontDeskException('A reason is required to reject a refund.');
        }

        return DB::connection('clinic')->transaction(function () use ($refund, $note): Refund {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($refund);

            $refund->update([
                'status' => RefundStatus::Rejected,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
                'decision_note' => trim($note),
            ]);

            $this->audit->record('refund.rejected', $refund, ['status' => RefundStatus::Pending->value], ['status' => RefundStatus::Rejected->value], ['note' => $refund->decision_note]);

            return $refund;
        });
    }

    private function assertPending(Refund $refund): void
    {
        if ($refund->status !== RefundStatus::Pending) {
            throw new FrontDeskException('This refund has already been '.strtolower($refund->status->label()).'.');
        }
    }
}
