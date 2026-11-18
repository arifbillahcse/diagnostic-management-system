<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Licence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Daily: moves every clinic to the subscription status its dates call for,
 * and emails the owner before the end date, when payment is overdue, and
 * when the clinic becomes read-only. Each reminder is sent once.
 */
final class ClinicLicenceCheck extends Command
{
    protected $signature = 'clinic:licence-check';

    protected $description = 'Update every clinic\'s subscription status from its dates and send the owner reminders';

    public function handle(Licence $licence, ClinicManager $manager): int
    {
        $changed = 0;
        $reminded = 0;

        foreach (Clinic::whereIn('status', ClinicStatus::usableValues())->orderBy('id')->get() as $clinic) {
            if ($licence->refresh($clinic) !== null) {
                $changed++;
                $clinic->refresh();
            }

            $reminder = $this->reminderFor($clinic, $licence);

            if ($reminder === null) {
                continue;
            }

            [$key, $subject, $body] = $reminder;

            if (DB::connection('control')->table('provisioning_log')->where('clinic_id', $clinic->id)->where('step', 'reminder')->where('message', $key)->exists()) {
                continue;
            }

            try {
                $emails = $manager->run($clinic, fn (): array => User::where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->pluck('email')->filter()->all());

                foreach ($emails as $email) {
                    Mail::raw($body, fn ($m) => $m->to($email)->subject($subject));
                }

                DB::connection('control')->table('provisioning_log')->insert([
                    'clinic_id' => $clinic->id, 'step' => 'reminder', 'status' => 'sent', 'message' => $key, 'created_at' => now(),
                ]);
                $reminded++;
            } catch (Throwable $e) {
                report($e);
                $this->warn("Reminder for {$clinic->name} not sent: {$e->getMessage()}");
            }
        }

        $this->info("{$changed} status change(s), {$reminded} reminder(s) sent.");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string}|null key (for sending once), subject, body */
    private function reminderFor(Clinic $clinic, Licence $licence): ?array
    {
        $end = $licence->endsOn($clinic);
        $days = $licence->daysLeft($clinic);

        if ($end === null || $days === null) {
            return null;
        }

        $date = $end->format('d M Y');
        $what = $clinic->paid_until === null ? 'free trial' : 'subscription';

        return match (true) {
            $clinic->status === ClinicStatus::ReadOnly => [
                "read_only:{$end->toDateString()}",
                "{$clinic->name} is now read-only",
                "Your {$what} ended on {$date}. {$clinic->name} can still view and print reports and bills, and patients can still open their reports online, but new bills and results cannot be entered until the subscription is paid.\n\nPlease contact your provider to renew.",
            ],
            $clinic->status === ClinicStatus::Overdue => [
                "overdue:{$end->toDateString()}",
                "{$clinic->name}: subscription payment overdue",
                "Your {$what} ended on {$date}. Everything keeps working until ".$licence->graceEndsOn($clinic)->format('d M Y').", then the system becomes read-only until the subscription is paid.\n\nPlease contact your provider to renew.",
            ],
            $days >= 0 && $days <= (int) config('dms.clinics.remind_days', 7) => [
                "soon:{$end->toDateString()}",
                "{$clinic->name}: your {$what} ends on {$date}",
                "Your {$what} ends on {$date} ({$days} day(s) left). Please contact your provider to renew, so that work is never interrupted.",
            ],
            default => null,
        };
    }
}
