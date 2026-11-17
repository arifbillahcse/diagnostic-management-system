<?php

declare(strict_types=1);

namespace Modules\People\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Models\Advance;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\AdvanceBook;
use Modules\People\Support\FinanceLink;
use Modules\People\Support\PeopleException;

/**
 * Salary advances: giving one (with finance on, an expense "Staff
 * advances") and voiding one entered by mistake, which voids its expense too.
 */
final class ManageAdvances
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly ClinicContext $clinics,
        private readonly AdvanceBook $book,
        private readonly FinanceLink $finance,
    ) {}

    public function give(Staff $staff, string $amount, string $date, string $reason, ?int $accountId = null): Advance
    {
        Gate::authorize(Permissions::ADVANCES_MANAGE, $staff->branch_id);

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($amount)) || ! ($money = Money::fromDecimal(trim($amount)))->isPositive()) {
            throw new PeopleException('Enter the advance as an amount such as 2000.');
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null;
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new PeopleException('Choose a valid date.');
        }
        if ($date > CarbonImmutable::now($this->clinics->timezone())->toDateString()) {
            throw new PeopleException('The date cannot be in the future.');
        }
        if (blank($reason)) {
            throw new PeopleException('Say what the advance is for.');
        }
        if ($staff->hasLeft() || ! $staff->worksOn($date)) {
            throw new PeopleException("{$staff->name} was not working on that day.");
        }

        return DB::connection('clinic')->transaction(function () use ($staff, $money, $date, $reason, $accountId): Advance {
            $advance = Advance::create([
                'advance_no' => $this->numbers->generate('advance', $staff->branch),
                'staff_id' => $staff->id,
                'branch_id' => $staff->branch_id,
                'amount' => $money->toDecimal(),
                'given_on' => $date,
                'reason' => trim($reason),
                'created_by' => Auth::id(),
            ]);

            $expenseId = $this->finance->expense($staff->branch_id, 'Staff advances', $accountId, $money, $date,
                "Advance {$advance->advance_no} to {$staff->name}", $staff->name);

            if ($expenseId) {
                $advance->forceFill(['expense_id' => $expenseId])->save();
            }

            $this->audit->record('advance.given', $advance, [], [
                'advance_no' => $advance->advance_no, 'staff' => $staff->name, 'amount' => $money->toDecimal(), 'date' => $date, 'expense_id' => $expenseId,
            ], ['reason' => trim($reason)]);

            return $advance;
        });
    }

    /** Only while the staff member still owes at least this much (nothing of it has been taken back). */
    public function void(Advance $advance, string $reason): Advance
    {
        Gate::authorize(Permissions::ADVANCES_MANAGE, $advance->branch_id);

        if (blank($reason)) {
            throw new PeopleException('Say why the advance is voided.');
        }

        return DB::connection('clinic')->transaction(function () use ($advance, $reason): Advance {
            $advance = Advance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            Staff::whereKey($advance->staff_id)->lockForUpdate()->first();

            if ($advance->isVoid()) {
                throw new PeopleException('This advance is already void.');
            }
            if ($this->book->outstanding($advance->staff_id)->lessThan($advance->amount)) {
                throw new PeopleException('Part of this advance has already been taken back from salary, so it cannot be voided.');
            }

            $advance->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => trim($reason)])->save();
            $this->finance->voidExpense($advance->expense_id, "Advance {$advance->advance_no} voided: ".trim($reason));

            $this->audit->record('advance.voided', $advance, ['state' => 'given'], ['state' => 'void'], ['reason' => trim($reason)]);

            return $advance;
        });
    }
}
