<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Enums\CashSessionStatus;
use Modules\FrontDesk\Events\CashSessionClosed;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\CashDrawer;
use Modules\FrontDesk\Support\FrontDeskException;

/**
 * Opens and closes cash sessions (daily cash closing).
 */
final class ManageCashSession
{
    public function __construct(
        private readonly CashDrawer $drawer,
        private readonly AuditLogger $audit,
    ) {}

    public function open(Branch $branch, Money $openingFloat): CashSession
    {
        Gate::authorize(Permissions::CASH_MANAGE, $branch);

        /** @var User $user */
        $user = Auth::user();

        if ($openingFloat->isNegative()) {
            throw new FrontDeskException('The opening amount cannot be negative.');
        }

        return DB::connection('clinic')->transaction(function () use ($branch, $openingFloat, $user): CashSession {
            // Locking the user's own row makes two simultaneous "open"
            // requests wait for each other, so only one session is created.
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($this->drawer->openSessionOf($user) !== null) {
                throw new FrontDeskException('You already have an open cash session. Close it first.');
            }

            $session = CashSession::create([
                'branch_id' => $branch->id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_float' => $openingFloat,
                'status' => CashSessionStatus::Open,
            ]);

            $this->audit->record('cash_session.opened', $session, [], ['opening_float' => $openingFloat->toDecimal()]);

            return $session;
        });
    }

    /**
     * Close a session with the cash actually counted. The difference from
     * the expected amount is recorded, never hidden. A closed session
     * cannot be reopened.
     */
    public function close(CashSession $session, Money $countedCash, ?string $notes = null): CashSession
    {
        /** @var User $user */
        $user = Auth::user();

        $own = $session->opened_by === $user->id;
        Gate::authorize($own ? Permissions::CASH_MANAGE : Permissions::CASH_VIEW_ALL, $session->branch_id);

        if ($countedCash->isNegative()) {
            throw new FrontDeskException('The counted amount cannot be negative.');
        }

        $session = DB::connection('clinic')->transaction(function () use ($session, $countedCash, $notes, $user): CashSession {
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw new FrontDeskException('This cash session is already closed.');
            }

            $expected = $this->drawer->expected($session);
            $difference = $countedCash->minus($expected);

            if (! $difference->isZero() && blank($notes)) {
                throw new FrontDeskException("The count differs from the expected {$expected} by {$difference}. Add a note explaining the difference.");
            }

            $session->update([
                'status' => CashSessionStatus::Closed,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'difference' => $difference,
                'notes' => filled($notes) ? trim($notes) : null,
            ]);

            $this->audit->record('cash_session.closed', $session, [], [
                'expected_cash' => $expected->toDecimal(),
                'counted_cash' => $countedCash->toDecimal(),
                'difference' => $difference->toDecimal(),
            ], filled($notes) ? ['notes' => trim($notes)] : []);

            return $session;
        });

        event(new CashSessionClosed($session));

        return $session;
    }
}
