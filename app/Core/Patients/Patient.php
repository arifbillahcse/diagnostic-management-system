<?php

declare(strict_types=1);

namespace App\Core\Patients;

use App\Core\Branches\Branch;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A patient. Shared by every module and every branch of a clinic.
 *
 * @property int $id
 * @property string $public_id
 * @property string $patient_code
 * @property string $name
 * @property Sex $sex
 * @property Carbon $date_of_birth
 * @property bool $dob_is_estimated
 * @property ?string $phone
 * @property ?string $phone_normalized
 */
class Patient extends ClinicModel
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id', 'patient_code'];

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'date_of_birth' => 'date',
            'dob_is_estimated' => 'boolean',
        ];
    }

    public function registeredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'registered_branch_id');
    }

    /** Age in whole days on a given date. Reference ranges are chosen with this. */
    public function ageInDays(?CarbonInterface $on = null): int
    {
        return max(0, (int) $this->date_of_birth->diffInDays(($on ?? now())->copy()->startOfDay(), false));
    }

    /** Age as staff read it: "34 Y", "7 M", or "12 D". */
    public function ageLabel(?CarbonInterface $on = null): string
    {
        $diff = $this->date_of_birth->diff(($on ?? now())->copy()->startOfDay());

        return match (true) {
            $diff->y >= 1 => $diff->y.' Y',
            $diff->m >= 1 => $diff->m.' M',
            default => $diff->d.' D',
        };
    }

    /**
     * Front-desk search. Every branch of the condition can use an index:
     * names and codes match from their start, phone numbers match after
     * normalising. A search for text in the middle of a name is not offered.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term);
        $looksLikePhone = strlen($digits) >= 4 && strlen($digits) >= strlen($term) - 4;

        return $query->where(function (Builder $q) use ($like, $term, $looksLikePhone): void {
            $q->where('patient_code', 'like', $like)->orWhere('name', 'like', $like);

            if ($looksLikePhone) {
                $normalized = PhoneNumber::normalize($term);
                $q->orWhere('phone_normalized', 'like', $normalized.'%');
            }
        });
    }
}
