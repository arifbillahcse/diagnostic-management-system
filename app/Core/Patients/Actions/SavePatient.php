<?php

declare(strict_types=1);

namespace App\Core\Patients\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Branches\Branch;
use App\Core\Numbering\NumberGenerator;
use App\Core\Patients\Patient;
use App\Core\Patients\PatientRegistered;
use App\Core\Patients\PhoneNumber;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Registers or updates a patient.
 *
 * Accepted data: name, sex, phone, address, email, blood_group, notes, and
 * either date_of_birth, or an age given as age_years / age_months / age_days.
 */
final class SavePatient
{
    private const FIELDS = ['name', 'sex', 'phone', 'address', 'email', 'blood_group', 'notes'];

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly ClinicContext $clinics,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(array $data, Branch $branch): Patient
    {
        Gate::authorize(CorePermissions::PATIENTS_CREATE);

        $patient = DB::connection('clinic')->transaction(function () use ($data, $branch): Patient {
            $patient = new Patient($this->attributes($data, null));
            $patient->patient_code = $this->numbers->generate('patient', $branch);
            $patient->registered_branch_id = $branch->id;
            $patient->created_by = Auth::id();
            $patient->save();

            $this->audit->record('patient.registered', $patient, [], $this->auditable($patient));

            return $patient;
        });

        event(new PatientRegistered($patient));

        return $patient;
    }

    /** @param array<string, mixed> $data */
    public function update(Patient $patient, array $data): Patient
    {
        Gate::authorize(CorePermissions::PATIENTS_UPDATE);

        return DB::connection('clinic')->transaction(function () use ($patient, $data): Patient {
            $old = $this->auditable($patient);

            $patient->fill($this->attributes($data, $patient));
            $patient->updated_by = Auth::id();
            $patient->save();

            [$before, $after] = $this->audit->diff($old, $this->auditable($patient));

            if ($after !== []) {
                $this->audit->record('patient.updated', $patient, $before, $after);
            }

            return $patient;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Patient $existing): array
    {
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));

        foreach ($attributes as $key => $value) {
            $attributes[$key] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        if (array_key_exists('phone', $attributes)) {
            $attributes['phone_normalized'] = PhoneNumber::normalize($attributes['phone']);
        }

        $today = CarbonImmutable::now($this->clinics->require()->timezone)->startOfDay();
        $hasAge = filled($data['age_years'] ?? null) || filled($data['age_months'] ?? null) || filled($data['age_days'] ?? null);

        if (filled($data['date_of_birth'] ?? null)) {
            $dob = CarbonImmutable::parse((string) $data['date_of_birth'], $today->timezone)->startOfDay();
            $estimated = false;
        } elseif ($hasAge) {
            $years = (int) ($data['age_years'] ?? 0);
            $months = (int) ($data['age_months'] ?? 0);
            $days = (int) ($data['age_days'] ?? 0);

            if ($years < 0 || $months < 0 || $days < 0 || $years > 130) {
                throw new InvalidArgumentException('The age is not valid.');
            }

            $dob = $today->subYears($years)->subMonths($months)->subDays($days);
            $estimated = true;
        } elseif ($existing === null) {
            throw new InvalidArgumentException('A date of birth or an age is required.');
        } else {
            return $attributes;
        }

        if ($dob->isAfter($today)) {
            throw new InvalidArgumentException('The date of birth cannot be in the future.');
        }

        return $attributes + ['date_of_birth' => $dob->toDateString(), 'dob_is_estimated' => $estimated];
    }

    /** @return array<string, mixed> */
    private function auditable(Patient $patient): array
    {
        return [
            ...$patient->only([...self::FIELDS, 'patient_code', 'dob_is_estimated']),
            'sex' => $patient->sex?->value,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
        ];
    }
}
