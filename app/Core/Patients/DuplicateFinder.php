<?php

declare(strict_types=1);

namespace App\Core\Patients;

use Illuminate\Database\Eloquent\Collection;

/**
 * Finds existing patients who may be the person being registered.
 *
 * One phone number often serves a whole family, so matches are only shown
 * to the receptionist. Nothing is ever merged automatically.
 */
final class DuplicateFinder
{
    /** @return Collection<int, Patient> */
    public function byPhone(?string $phone, ?int $exceptId = null): Collection
    {
        $normalized = PhoneNumber::normalize($phone);

        if ($normalized === null || strlen($normalized) < 7) {
            return new Collection;
        }

        return Patient::query()
            ->where('phone_normalized', $normalized)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }
}
