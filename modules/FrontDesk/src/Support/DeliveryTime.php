<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\FrontDesk\Permissions;

/**
 * When a report is promised: a number of days after the order, at a fixed
 * time of day (by default the next day at 7 PM), in the clinic's own time.
 */
final class DeliveryTime
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ClinicContext $clinics,
    ) {}

    public function days(): int
    {
        return max(0, min(30, (int) $this->settings->get(Permissions::SETTING_DELIVERY_DAYS, 1)));
    }

    /** "HH:MM", 24-hour. */
    public function time(): string
    {
        $time = (string) $this->settings->get(Permissions::SETTING_DELIVERY_TIME, '19:00');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '19:00';
    }

    public function after(CarbonInterface $orderedAt): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->time()));

        return CarbonImmutable::instance($orderedAt)
            ->setTimezone($this->clinics->require()->timezone)
            ->addDays($this->days())
            ->setTime($hour, $minute)
            ->utc();
    }
}
