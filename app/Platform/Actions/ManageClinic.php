<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Core\Tenancy\Actions\ChangePlan;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Licence;
use App\Core\Tenancy\Plan;
use App\Platform\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the platform console does to a clinic. Only a signed-in platform
 * user may; every step is written to the platform audit log (and, where
 * the clinic is touched, its own log).
 */
final class ManageClinic
{
    public function __construct(
        private readonly ChangePlan $plans,
        private readonly Licence $licence,
    ) {}

    /** @return array{switched_off: list<string>, branches: int, branch_limit: ?int} */
    public function changePlan(Clinic $clinic, Plan $plan): array
    {
        $this->authorize();
        $from = $clinic->plan?->key;
        $result = $this->plans->handle($clinic, $plan);
        PlatformAudit::record('clinic.plan_changed', $clinic->id, ['from' => $from, 'to' => $plan->key, 'switched_off' => $result['switched_off']]);

        return $result;
    }

    public function setDates(Clinic $clinic, ?string $trialEndsOn, ?string $paidUntil): Clinic
    {
        $this->authorize();

        foreach ([$trialEndsOn, $paidUntil] as $date) {
            if ($date !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) {
                throw new RuntimeException('Dates are written YYYY-MM-DD, for example 2027-01-31.');
            }
        }

        $before = ['trial_ends' => $clinic->trial_ends_at?->toDateString(), 'paid_until' => $clinic->paid_until?->toDateString(), 'status' => $clinic->status->value];
        $clinic = $this->licence->setDates($clinic, $trialEndsOn, $paidUntil);
        PlatformAudit::record('clinic.dates_set', $clinic->id, ['before' => $before, 'after' => [
            'trial_ends' => $clinic->trial_ends_at?->toDateString(), 'paid_until' => $clinic->paid_until?->toDateString(), 'status' => $clinic->status->value,
        ]]);

        return $clinic;
    }

    public function extendTrial(Clinic $clinic, int $days): Clinic
    {
        if ($days < 1 || $days > 365) {
            throw new RuntimeException('Extend by 1 to 365 days.');
        }

        $base = $clinic->trial_ends_at && $clinic->trial_ends_at->isFuture() ? CarbonImmutable::parse($clinic->trial_ends_at) : CarbonImmutable::now();

        return $this->setDates($clinic, $base->setTimezone($clinic->timezone ?: 'Asia/Dhaka')->addDays($days)->toDateString(), null);
    }

    /** Stops serving the clinic's address at once (for example, after abuse or a request to stop). */
    public function suspend(Clinic $clinic, string $reason): Clinic
    {
        $this->authorize();
        $this->reason($reason);

        return $this->setStatus($clinic, ClinicStatus::Suspended, $reason);
    }

    /** Serves the clinic again; its dates then decide trial, active, overdue or read-only. */
    public function reactivate(Clinic $clinic, string $reason): Clinic
    {
        $this->authorize();
        $this->reason($reason);

        if (! in_array($clinic->status, [ClinicStatus::Suspended, ClinicStatus::Closed], true)) {
            throw new RuntimeException('This clinic is not suspended or closed.');
        }

        $clinic = $this->setStatus($clinic, $clinic->paid_until !== null ? ClinicStatus::Active : ClinicStatus::Trial, $reason);
        $this->licence->refresh($clinic);

        return $clinic->fresh();
    }

    private function setStatus(Clinic $clinic, ClinicStatus $status, string $reason): Clinic
    {
        $from = $clinic->status;

        DB::connection('control')->transaction(function () use ($clinic, $status, $from, $reason): void {
            $clinic->forceFill(['status' => $status])->save();
            DB::connection('control')->table('provisioning_log')->insert([
                'clinic_id' => $clinic->id, 'step' => 'status', 'status' => $status->value,
                'message' => "{$from->value} → {$status->value}: ".trim($reason), 'created_at' => now(),
            ]);
            PlatformAudit::record('clinic.status_changed', $clinic->id, ['from' => $from->value, 'to' => $status->value, 'reason' => trim($reason)]);
        });

        return $clinic->fresh();
    }

    private function reason(string $reason): void
    {
        if (blank($reason)) {
            throw new RuntimeException('Give a reason; it is kept in the log.');
        }
    }

    private function authorize(): void
    {
        if (! Auth::guard('platform')->check()) {
            throw new RuntimeException('Only a signed-in platform user can do this.');
        }
    }
}
