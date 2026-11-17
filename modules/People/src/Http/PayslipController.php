<?php

declare(strict_types=1);

namespace Modules\People\Http;

use App\Core\Documents\Letterhead;
use App\Core\Money\AmountInWords;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\People\Models\SalaryLine;
use Modules\People\Models\SalaryRun;
use Modules\People\Permissions;

/**
 * Printable payslips, one per page (A5). Only approved or paid runs print.
 */
final class PayslipController
{
    public function __construct(private readonly Letterhead $letterhead) {}

    public function one(SalaryLine $line): View
    {
        return $this->render($line->run, $line->newCollection([$line]));
    }

    public function run(SalaryRun $run): View
    {
        return $this->render($run, $run->lines()->get());
    }

    /** One sheet for the whole run: every person, their pay, and a column to sign on receipt. */
    public function sheet(SalaryRun $run): View
    {
        Gate::authorize(Permissions::SALARY_MANAGE, $run->branch_id);
        abort_unless($run->isSettled(), 404);

        return view('people::print.salary-sheet', [
            'clinic' => $this->letterhead->data($run->branch),
            'run' => $run->load('branch'),
            'lines' => $run->lines()->with('staff')->get()->sortBy(fn (SalaryLine $l) => $l->staff->name)->values(),
        ]);
    }

    /** @param Collection<int, SalaryLine> $lines */
    private function render(SalaryRun $run, $lines): View
    {
        Gate::authorize(Permissions::SALARY_MANAGE, $run->branch_id);
        abort_unless($run->isSettled(), 404);

        $lines->load('staff');

        return view('people::print.payslip', [
            'clinic' => $this->letterhead->data($run->branch),
            'run' => $run->load('branch'),
            'slips' => $lines->sortBy(fn (SalaryLine $l) => $l->staff->name)->values()
                ->map(fn (SalaryLine $l) => ['line' => $l, 'words' => AmountInWords::for($l->net)]),
        ]);
    }
}
