<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A clinic's subscription dates and what they mean today.
 *
 * The clinic is covered until its paid-until date, or, before its first
 * payment, its trial end. For the grace days after that everything still
 * works (overdue); then the clinic is read-only until it pays. A clinic
 * with neither date is never limited. Suspended and closed are set by the
 * platform and left alone here.
 */
final class Licence
{
    /** The last day the clinic is covered, or null for no end. */
    public function endsOn(Clinic $clinic): ?CarbonImmutable
    {
        $tz = $clinic->timezone ?: 'Asia/Dhaka';

        if ($clinic->paid_until !== null) {
            return CarbonImmutable::parse($clinic->paid_until->toDateString(), $tz)->endOfDay();
        }

        return $clinic->trial_ends_at ? CarbonImmutable::parse($clinic->trial_ends_at)->setTimezone($tz)->endOfDay() : null;
    }

    public function graceEndsOn(Clinic $clinic): ?CarbonImmutable
    {
        return $this->endsOn($clinic)?->addDays((int) config('dms.clinics.grace_days', 7));
    }

    /** Whole days left until the end (negative once past it), or null for no end. */
    public function daysLeft(Clinic $clinic): ?int
    {
        $end = $this->endsOn($clinic);

        return $end === null ? null : (int) floor(CarbonImmutable::now($end->timezone)->startOfDay()->diffInDays($end->startOfDay(), false));
    }

    public function statusFor(Clinic $clinic): ClinicStatus
    {
        if (in_array($clinic->status, [ClinicStatus::Suspended, ClinicStatus::Closed], true)) {
            return $clinic->status;
        }

        $end = $this->endsOn($clinic);
        $paid = $clinic->paid_until !== null;

        if ($end === null || now()->lessThanOrEqualTo($end)) {
            // A clinic marked active by the platform stays active without dates.
            return $paid || $clinic->status === ClinicStatus::Active ? ClinicStatus::Active : ClinicStatus::Trial;
        }

        return now()->lessThanOrEqualTo($this->graceEndsOn($clinic)) ? ClinicStatus::Overdue : ClinicStatus::ReadOnly;
    }

    /** Moves the clinic to the status its dates call for. Returns the old status when it changed. */
    public function refresh(Clinic $clinic): ?ClinicStatus
    {
        $next = $this->statusFor($clinic);

        if ($next === $clinic->status) {
            return null;
        }

        $old = $clinic->status;

        DB::connection('control')->transaction(function () use ($clinic, $next, $old): void {
            $clinic->forceFill(['status' => $next])->save();
            DB::connection('control')->table('provisioning_log')->insert([
                'clinic_id' => $clinic->id, 'step' => 'licence', 'status' => $next->value,
                'message' => "Subscription status {$old->value} → {$next->value}", 'created_at' => now(),
            ]);
        });

        return $old;
    }

    /** Sets the dates (a platform task) and refreshes the status. */
    public function setDates(Clinic $clinic, ?string $trialEndsOn, ?string $paidUntil): Clinic
    {
        $changes = [];
        if ($trialEndsOn !== null) {
            // Stored in UTC, as every timestamp is.
            $changes['trial_ends_at'] = CarbonImmutable::parse($trialEndsOn, $clinic->timezone ?: 'Asia/Dhaka')->endOfDay()->utc();
        }
        if ($paidUntil !== null) {
            $changes['paid_until'] = CarbonImmutable::parse($paidUntil)->toDateString();
        }

        DB::connection('control')->transaction(function () use ($clinic, $changes): void {
            $clinic->forceFill($changes)->save();
            DB::connection('control')->table('provisioning_log')->insert([
                'clinic_id' => $clinic->id, 'step' => 'licence_dates', 'status' => 'done',
                'message' => 'Dates set: '.json_encode(array_map(fn ($v) => (string) $v, $changes)), 'created_at' => now(),
            ]);
        });

        $this->refresh($clinic->fresh());

        return $clinic->fresh();
    }
}
