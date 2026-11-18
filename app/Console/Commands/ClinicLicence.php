<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\Licence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Shows or sets a clinic's trial end and paid-until dates (until the platform console arrives). */
final class ClinicLicence extends Command
{
    protected $signature = 'clinic:licence
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--trial-ends= : Last day of the free trial, YYYY-MM-DD}
        {--paid-until= : Last day paid for, YYYY-MM-DD}
        {--extend-trial= : Add this many days to the trial}';

    protected $description = 'Show or set a clinic\'s trial end and paid-until dates; its status follows at once';

    public function handle(Licence $licence): int
    {
        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        $trial = $this->option('trial-ends');
        if (filled($this->option('extend-trial'))) {
            $base = $clinic->trial_ends_at ? CarbonImmutable::parse($clinic->trial_ends_at) : CarbonImmutable::now();
            $trial = $base->addDays((int) $this->option('extend-trial'))->toDateString();
        }

        foreach (['trial' => $trial, 'paid' => $this->option('paid-until')] as $value) {
            if (filled($value) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                $this->error('Dates are written YYYY-MM-DD, for example 2027-01-31.');

                return self::FAILURE;
            }
        }

        if (filled($trial) || filled($this->option('paid-until'))) {
            $clinic = $licence->setDates($clinic, $trial ?: null, $this->option('paid-until') ?: null);
        }

        $end = $licence->endsOn($clinic);
        $this->table(['Clinic', 'Status', 'Trial ends', 'Paid until', 'Covered until', 'Read-only from'], [[
            $clinic->name, $clinic->status->label(),
            $clinic->trial_ends_at?->format('Y-m-d') ?? '-', $clinic->paid_until?->format('Y-m-d') ?? '-',
            $end?->format('Y-m-d') ?? 'no end', $licence->graceEndsOn($clinic)?->addDay()->format('Y-m-d') ?? '-',
        ]]);

        return self::SUCCESS;
    }
}
