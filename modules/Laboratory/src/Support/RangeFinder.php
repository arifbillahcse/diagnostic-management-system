<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use Carbon\CarbonInterface;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\ReferenceRange;

/**
 * Chooses the reference range for an analyte, a patient, and a date.
 * See docs/schema.md, section 5, "Reference range lookup".
 */
final class RangeFinder
{
    /**
     * @param  string  $sex  "male", "female", or "other"
     * @param  int  $ageDays  the patient's age in days when the sample was collected
     */
    public function find(Analyte $analyte, string $sex, int $ageDays, CarbonInterface $on): ?ReferenceRange
    {
        $date = $on->toDateString();

        $candidates = $analyte->ranges
            ->filter(fn (ReferenceRange $r): bool => $ageDays >= $r->age_min_days && $ageDays <= $r->age_max_days)
            ->filter(fn (ReferenceRange $r): bool => ($r->effective_from === null || $r->effective_from->toDateString() <= $date)
                && ($r->effective_to === null || $r->effective_to->toDateString() >= $date));

        // A range for the patient's own sex wins over a general one.
        $own = $candidates->where('sex', $sex);
        $pool = $own->isNotEmpty() ? $own : $candidates->where('sex', 'any');

        // If the catalog holds overlapping ranges, the narrowest age band is
        // the most specific; the newest breaks a tie.
        return $pool
            ->sortBy([
                fn (ReferenceRange $a, ReferenceRange $b): int => ($a->age_max_days - $a->age_min_days) <=> ($b->age_max_days - $b->age_min_days),
                fn (ReferenceRange $a, ReferenceRange $b): int => $b->id <=> $a->id,
            ])
            ->first();
    }
}
