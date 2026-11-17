<?php

declare(strict_types=1);

namespace Modules\People\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Enums;
use Modules\People\Models\Attendance;
use Modules\People\Models\Staff;
use Modules\People\Permissions;
use Modules\People\Support\PeopleException;

/**
 * Saves one day's attendance sheet for a branch. A new mark or a changed
 * mark is audited; an unchanged row is left alone. A mark can be changed
 * but not removed.
 */
final class RecordAttendance
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * @param  array<int|string, array{status?: ?string, in_time?: ?string, out_time?: ?string, note?: ?string}>  $rows  keyed by staff id
     * @return int how many marks were added or changed
     */
    public function day(Branch $branch, string $date, array $rows): int
    {
        Gate::authorize(Permissions::ATTENDANCE_RECORD, $branch->id);

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null;

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new PeopleException('Choose a valid date.');
        }
        if ($date > CarbonImmutable::now($this->clinics->timezone())->toDateString()) {
            throw new PeopleException('Attendance cannot be recorded for a future day.');
        }

        if (RunPayroll::monthSettled($branch->id, substr($date, 0, 7))) {
            throw new PeopleException('The salary run for '.$parsed->format('F Y').' is approved, so its attendance is locked. Cancel the run to change it.');
        }

        $clean = [];

        foreach ($rows as $staffId => $row) {
            $status = $row['status'] ?? null;

            if (blank($status)) {
                continue;
            }

            $staff = Staff::where('branch_id', $branch->id)->find((int) $staffId)
                ?? throw new PeopleException('A staff member on the sheet is not in this branch.');

            if (! array_key_exists($status, Enums::STATUSES)) {
                throw new PeopleException("Choose a valid mark for {$staff->name}.");
            }
            if (! $staff->worksOn($date)) {
                throw new PeopleException("{$staff->name} did not work here on ".$parsed->format('d M Y').'.');
            }

            $in = $this->time($row['in_time'] ?? null, $staff->name);
            $out = $this->time($row['out_time'] ?? null, $staff->name);

            if (($in || $out) && ! in_array($status, Enums::WORKED, true)) {
                throw new PeopleException("{$staff->name} is marked ".strtolower(Enums::status($status)).': leave the times empty.');
            }
            if ($in && $out && $out <= $in) {
                throw new PeopleException("{$staff->name}: the out time must be after the in time.");
            }

            $clean[$staff->id] = [
                'status' => $status,
                'in_time' => $in,
                'out_time' => $out,
                'note' => blank($row['note'] ?? null) ? null : mb_substr(trim((string) $row['note']), 0, 255),
            ];
        }

        return DB::connection('clinic')->transaction(function () use ($clean, $date): int {
            $saved = 0;

            foreach ($clean as $staffId => $row) {
                $existing = Attendance::where('staff_id', $staffId)->whereDate('date', $date)->lockForUpdate()->first();

                if ($existing === null) {
                    $mark = Attendance::create($row + ['staff_id' => $staffId, 'date' => $date, 'recorded_by' => Auth::id()]);
                    $this->audit->record('attendance.recorded', $mark, [], $row + ['date' => $date]);
                    $saved++;

                    continue;
                }

                $before = [
                    'status' => $existing->status,
                    'in_time' => $this->short($existing->in_time),
                    'out_time' => $this->short($existing->out_time),
                    'note' => $existing->note,
                ];
                $changed = array_keys(array_filter($row, fn ($value, $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

                if ($changed === []) {
                    continue;
                }

                $existing->forceFill($row + ['recorded_by' => Auth::id()])->save();
                $keep = array_flip($changed);
                $this->audit->record('attendance.changed', $existing, array_intersect_key($before, $keep), array_intersect_key($row, $keep), ['date' => $date]);
                $saved++;
            }

            return $saved;
        });
    }

    private function time(?string $value, string $name): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = $this->short(trim($value));

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)) {
            throw new PeopleException("{$name}: enter times such as 09:30 or 17:45.");
        }

        return $value;
    }

    /** "09:30:00" → "09:30" */
    private function short(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
