<?php

declare(strict_types=1);

namespace Modules\People\Support;

use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Modules\People\Enums;
use Modules\People\Models\Attendance;
use Modules\People\Models\Staff;

/**
 * The month view: every staff member employed in the month, their mark
 * for each day, and the totals the salary run will use.
 */
final class AttendanceReport
{
    public function __construct(private readonly ClinicContext $clinics) {}

    /**
     * @return array{days: list<string>, rows: list<array{staff: Staff, marks: array<string, ?string>, totals: array<string, int|float>}>}
     */
    public function month(?int $branchId, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month) ?: throw new PeopleException('Choose a valid month.');
        $from = $start->startOfMonth()->toDateString();
        $to = $start->endOfMonth()->toDateString();
        $today = CarbonImmutable::now($this->clinics->timezone())->toDateString();

        $days = [];
        for ($d = $start->startOfMonth(); $d->toDateString() <= $to; $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $staff = Staff::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->employedBetween($from, $to)
            ->orderBy('name')->get();

        $marks = Attendance::whereIn('staff_id', $staff->modelKeys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
            ->groupBy('staff_id');

        $rows = [];

        foreach ($staff as $person) {
            $byDay = [];
            foreach ($marks->get($person->id, collect()) as $mark) {
                $byDay[$mark->date->toDateString()] = $mark->status;
            }

            $totals = array_fill_keys(array_keys(Enums::STATUSES), 0) + ['unmarked' => 0, 'worked' => 0.0];
            $cells = [];

            foreach ($days as $day) {
                if (! $person->worksOn($day)) {
                    $cells[$day] = null;

                    continue;
                }

                $status = $byDay[$day] ?? null;
                $cells[$day] = $status ?? '';

                if ($status === null) {
                    if ($day <= $today) {
                        $totals['unmarked']++;
                    }

                    continue;
                }

                $totals[$status]++;
                $totals['worked'] += $status === 'half_day' ? 0.5 : (in_array($status, Enums::WORKED, true) ? 1 : 0);
            }

            $rows[] = ['staff' => $person, 'marks' => $cells, 'totals' => $totals];
        }

        return ['days' => $days, 'rows' => $rows];
    }
}
