<?php

declare(strict_types=1);

namespace Modules\People\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Numbering\NumberGenerator;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Models\Advance;
use Modules\People\Models\SalaryLine;
use Modules\People\Models\SalaryRun;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\AdvanceBook;
use Modules\People\Support\AttendanceReport;
use Modules\People\Support\FinanceLink;
use Modules\People\Support\PeopleException;

/**
 * The monthly salary run of one branch, and its state machine:
 *
 *   draft → approved → paid
 *   draft → cancelled, approved → cancelled
 *
 * A draft is built from the salary history and the month's attendance.
 * Deductions are typed by hand (nothing is deducted automatically) and
 * advances are taken back up to what is owed. Approving locks the lines
 * and the month's attendance. Paying records one expense "Salaries" when
 * finance is on. A paid run is final.
 */
final class RunPayroll
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly ClinicContext $clinics,
        private readonly AdvanceBook $book,
        private readonly AttendanceReport $attendance,
        private readonly FinanceLink $finance,
    ) {}

    public function start(Branch $branch, string $month): SalaryRun
    {
        Gate::authorize(Permissions::PAYROLL_PREPARE, $branch->id);

        $first = CarbonImmutable::createFromFormat('!Y-m', $month) ?: null;

        if (! $first || $first->format('Y-m') !== $month) {
            throw new PeopleException('Choose a valid month.');
        }
        if ($month > CarbonImmutable::now($this->clinics->timezone())->format('Y-m')) {
            throw new PeopleException('A salary run cannot be made for a future month.');
        }

        return DB::connection('clinic')->transaction(function () use ($branch, $month): SalaryRun {
            if (SalaryRun::where('live_key', "{$branch->id}:{$month}")->lockForUpdate()->exists()) {
                throw new PeopleException("{$branch->name} already has a salary run for ".CarbonImmutable::createFromFormat('!Y-m', $month)->format('F Y').'.');
            }

            $run = SalaryRun::create([
                'run_no' => $this->numbers->generate('salary_run', $branch),
                'branch_id' => $branch->id,
                'month' => $month,
                'status' => 'draft',
                'live_key' => "{$branch->id}:{$month}",
                'created_by' => Auth::id(),
            ]);

            $this->build($run);
            $this->audit->record('salary_run.started', $run, [], ['run_no' => $run->run_no, 'month' => $month, 'branch' => $branch->name]);

            return $run->refresh();
        });
    }

    /** Rebuild a draft from the latest salaries and attendance, keeping typed deductions and recoveries. */
    public function refresh(SalaryRun $run): SalaryRun
    {
        Gate::authorize(Permissions::PAYROLL_PREPARE, $run->branch_id);

        return DB::connection('clinic')->transaction(function () use ($run): SalaryRun {
            $run = $this->locked($run, 'draft');
            $this->build($run);
            $this->audit->record('salary_run.refreshed', $run, [], ['run_no' => $run->run_no, 'total_net' => $run->refresh()->total_net->toDecimal()]);

            return $run;
        });
    }

    /** Set one person's deduction and advance recovery in a draft. */
    public function setLine(SalaryLine $line, string $deduction, ?string $note, string $advanceRecovery): SalaryLine
    {
        $run = SalaryRun::findOrFail($line->run_id);
        Gate::authorize(Permissions::PAYROLL_PREPARE, $run->branch_id);

        $deduct = $this->amount($deduction, 'The deduction');
        $recover = $this->amount($advanceRecovery, 'The advance taken back');

        if ($deduct->isPositive() && blank($note)) {
            throw new PeopleException('Say why the deduction is made, for example "3 days absent".');
        }

        return DB::connection('clinic')->transaction(function () use ($run, $line, $deduct, $recover, $note): SalaryLine {
            $run = $this->locked($run, 'draft');
            $line = SalaryLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $owed = $this->book->outstanding($line->staff_id);

            if ($recover->greaterThan($owed)) {
                throw new PeopleException('Only ৳ '.$owed->formatted().' of advance is owed.');
            }

            $net = $line->gross->minus($deduct)->minus($recover);

            if ($net->isNegative()) {
                throw new PeopleException('The deduction and the advance taken back are more than the salary of ৳ '.$line->gross->formatted().'.');
            }

            $before = ['deduction' => $line->deduction->toDecimal(), 'deduction_note' => $line->deduction_note, 'advance_recovery' => $line->advance_recovery->toDecimal()];

            $line->forceFill([
                'deduction' => $deduct->toDecimal(),
                'deduction_note' => $deduct->isPositive() ? mb_substr(trim((string) $note), 0, 255) : null,
                'advance_recovery' => $recover->toDecimal(),
                'net' => $net->toDecimal(),
            ])->save();

            $after = ['deduction' => $deduct->toDecimal(), 'deduction_note' => $line->deduction_note, 'advance_recovery' => $recover->toDecimal()];

            if ($before !== $after) {
                $this->audit->record('salary_run.line_changed', $run, $before, $after, ['staff_id' => $line->staff_id, 'run_no' => $run->run_no]);
            }

            $this->total($run);

            return $line;
        });
    }

    /** draft → approved */
    public function approve(SalaryRun $run): SalaryRun
    {
        Gate::authorize(Permissions::PAYROLL_APPROVE, $run->branch_id);

        return DB::connection('clinic')->transaction(function () use ($run): SalaryRun {
            $run = $this->locked($run, 'draft');

            if (! $run->lines()->exists()) {
                throw new PeopleException('This salary run has nobody in it.');
            }

            // Another run may have taken back the same advance since this draft was made.
            foreach ($run->lines()->with('staff')->get() as $line) {
                Staff::whereKey($line->staff_id)->lockForUpdate()->first();

                if ($line->advance_recovery->greaterThan($this->book->outstanding($line->staff_id))) {
                    throw new PeopleException("{$line->staff->name} now owes less advance than this run takes back. Change the line first.");
                }
            }

            $this->move($run, 'approved', ['approved_at' => now(), 'approved_by' => Auth::id()]);
            $this->audit->record('salary_run.approved', $run, ['status' => 'draft'], ['status' => 'approved', 'total_net' => $run->total_net->toDecimal()], ['run_no' => $run->run_no]);

            return $run;
        });
    }

    /** approved → paid */
    public function pay(SalaryRun $run, string $date, ?int $accountId = null): SalaryRun
    {
        Gate::authorize(Permissions::PAYROLL_PAY, $run->branch_id);

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null;
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new PeopleException('Choose a valid date.');
        }
        if ($date > CarbonImmutable::now($this->clinics->timezone())->toDateString()) {
            throw new PeopleException('The payment date cannot be in the future.');
        }

        return DB::connection('clinic')->transaction(function () use ($run, $date, $accountId): SalaryRun {
            $run = $this->locked($run, 'approved');

            if ($date < $run->month.'-01') {
                throw new PeopleException('Salaries cannot be paid before the month starts.');
            }

            $expenseId = $this->finance->expense($run->branch_id, 'Salaries', $accountId, $run->total_net, $date,
                "Salaries {$run->monthLabel()} ({$run->run_no})");

            $this->move($run, 'paid', ['paid_on' => $date, 'paid_at' => now(), 'paid_by' => Auth::id(), 'expense_id' => $expenseId]);
            $this->audit->record('salary_run.paid', $run, ['status' => 'approved'], [
                'status' => 'paid', 'paid_on' => $date, 'total_net' => $run->total_net->toDecimal(), 'expense_id' => $expenseId,
            ], ['run_no' => $run->run_no]);

            return $run;
        });
    }

    /** draft or approved → cancelled. Frees the month and the advances it took back. */
    public function cancel(SalaryRun $run, string $reason): SalaryRun
    {
        Gate::authorize($run->status === 'approved' ? Permissions::PAYROLL_APPROVE : Permissions::PAYROLL_PREPARE, $run->branch_id);

        if (blank($reason)) {
            throw new PeopleException('Say why the salary run is cancelled.');
        }

        return DB::connection('clinic')->transaction(function () use ($run, $reason): SalaryRun {
            $run = SalaryRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $from = $run->status;

            if (! in_array('cancelled', SalaryRun::TRANSITIONS[$from], true)) {
                throw new PeopleException('A '.strtolower(SalaryRun::STATUSES[$from]).' salary run cannot be cancelled.');
            }

            Gate::authorize($from === 'approved' ? Permissions::PAYROLL_APPROVE : Permissions::PAYROLL_PREPARE, $run->branch_id);

            $this->move($run, 'cancelled', ['cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => trim($reason), 'live_key' => null]);
            $this->audit->record('salary_run.cancelled', $run, ['status' => $from], ['status' => 'cancelled'], ['run_no' => $run->run_no, 'reason' => trim($reason)]);

            return $run;
        });
    }

    /** Is attendance of this branch and month locked by an approved or paid run? */
    public static function monthSettled(int $branchId, string $month): bool
    {
        return SalaryRun::where('branch_id', $branchId)->where('month', $month)->whereIn('status', ['approved', 'paid'])->exists();
    }

    private function build(SalaryRun $run): void
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $run->month);
        $daysInMonth = $first->daysInMonth;
        $report = $this->attendance->month($run->branch_id, $run->month);
        $existing = $run->lines()->get()->keyBy('staff_id');
        $kept = [];

        foreach ($report['rows'] as $row) {
            /** @var Staff $staff */
            $staff = $row['staff'];
            $employed = array_keys(array_filter($row['marks'], fn ($mark): bool => $mark !== null));
            $lastDay = end($employed);
            $salary = $staff->salaryOn($lastDay);
            $days = count($employed);
            $gross = $days === $daysInMonth ? $salary : $salary->allocate([$days, $daysInMonth - $days])[0];

            $line = $existing->get($staff->id);
            $deduction = $line?->deduction ?? Money::zero();
            $owed = $this->book->outstanding($staff->id);
            // An existing line keeps its typed figure, plus any advance given since it was last built.
            $recovery = $line ? $line->advance_recovery->plus($this->givenSince($staff->id, $line->updated_at)) : $owed;

            // Keep what was typed, but never more than the salary or the advance owed.
            if ($deduction->greaterThan($gross)) {
                $deduction = $gross;
            }
            foreach ([$owed, $gross->minus($deduction)] as $cap) {
                if ($recovery->greaterThan($cap)) {
                    $recovery = $cap;
                }
            }

            $values = [
                'monthly_salary' => $salary->toDecimal(),
                'days_in_month' => $daysInMonth,
                'days_employed' => $days,
                'days_worked' => (string) $row['totals']['worked'],
                'days_absent' => $row['totals']['absent'],
                'days_leave' => $row['totals']['leave'],
                'days_off' => $row['totals']['off'] + $row['totals']['holiday'],
                'days_unmarked' => $row['totals']['unmarked'],
                'gross' => $gross->toDecimal(),
                'deduction' => $deduction->toDecimal(),
                'deduction_note' => $deduction->isPositive() ? $line?->deduction_note : null,
                'advance_recovery' => $recovery->toDecimal(),
                'net' => $gross->minus($deduction)->minus($recovery)->toDecimal(),
            ];

            $line ? $line->forceFill($values)->save() : SalaryLine::create($values + ['run_id' => $run->id, 'staff_id' => $staff->id]);
            $kept[] = $staff->id;
        }

        $existing->except($kept)->each->delete();
        $this->total($run);
    }

    private function givenSince(int $staffId, ?\DateTimeInterface $since): Money
    {
        return $since === null ? Money::zero() : Money::sum(...Advance::where('staff_id', $staffId)->whereNull('voided_at')
            ->where('created_at', '>', $since)->get()->map->amount->all());
    }

    /** Staff in a draft who were given an advance after their line was built. @return list<string> names */
    public function newAdvances(SalaryRun $run): array
    {
        return $run->lines()->with('staff')->get()
            ->filter(fn (SalaryLine $l): bool => $this->givenSince($l->staff_id, $l->updated_at)->isPositive())
            ->map(fn (SalaryLine $l): string => $l->staff->name)->values()->all();
    }

    private function total(SalaryRun $run): void
    {
        $lines = $run->lines()->get();

        $run->forceFill([
            'total_gross' => Money::sum(...$lines->map->gross->all())->toDecimal(),
            'total_deductions' => Money::sum(...$lines->map->deduction->all())->toDecimal(),
            'total_advances' => Money::sum(...$lines->map->advance_recovery->all())->toDecimal(),
            'total_net' => Money::sum(...$lines->map->net->all())->toDecimal(),
        ])->save();
    }

    private function locked(SalaryRun $run, string $status): SalaryRun
    {
        $run = SalaryRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

        if ($run->status !== $status) {
            throw new PeopleException('This salary run is '.strtolower(SalaryRun::STATUSES[$run->status]).', not '.strtolower(SalaryRun::STATUSES[$status]).'.');
        }

        return $run;
    }

    /** @param array<string, mixed> $extra */
    private function move(SalaryRun $run, string $to, array $extra): void
    {
        if (! in_array($to, SalaryRun::TRANSITIONS[$run->status], true)) {
            throw new PeopleException("A salary run cannot go from {$run->status} to {$to}.");
        }

        $run->forceFill(['status' => $to] + $extra)->save();
    }

    private function amount(string $value, string $what): Money
    {
        $value = trim($value) === '' ? '0' : trim($value);

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            throw new PeopleException("{$what} must be an amount such as 500 or 499.50.");
        }

        return Money::fromDecimal($value);
    }
}
