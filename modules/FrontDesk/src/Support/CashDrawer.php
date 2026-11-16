<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\CashSessionStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Models\CashSession;

/**
 * Finds a user's open cash session and works out what a session should hold.
 */
final class CashDrawer
{
    public function openSessionOf(User $user): ?CashSession
    {
        return CashSession::query()
            ->where('opened_by', $user->id)
            ->where('status', CashSessionStatus::Open->value)
            ->first();
    }

    /** The user's open session in the given branch, or a refusal staff can act on. */
    public function requireOpen(User $user, int $branchId): CashSession
    {
        $session = $this->openSessionOf($user);

        if ($session === null) {
            throw new FrontDeskException('Open your cash session before taking or giving cash.');
        }

        if ($session->branch_id !== $branchId) {
            throw new FrontDeskException('Your open cash session belongs to another branch. Close it first.');
        }

        return $session;
    }

    public function cashIn(CashSession $session): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fd_payments')
            ->where('cash_session_id', $session->id)
            ->where('method', PaymentMethod::Cash->value)
            ->sum('amount'));
    }

    public function cashOut(CashSession $session): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fd_refunds')
            ->where('cash_session_id', $session->id)
            ->where('method', PaymentMethod::Cash->value)
            ->where('status', RefundStatus::Approved->value)
            ->sum('amount'));
    }

    /** Opening float, plus cash received, minus cash refunded. */
    public function expected(CashSession $session): Money
    {
        return $session->opening_float->plus($this->cashIn($session))->minus($this->cashOut($session));
    }
}
