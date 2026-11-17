<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Holds the clinic the current request, job, or command is working for.
 */
final class ClinicContext
{
    private ?Clinic $clinic = null;

    public function set(Clinic $clinic): void
    {
        $this->clinic = $clinic;
    }

    public function clear(): void
    {
        $this->clinic = null;
    }

    public function get(): ?Clinic
    {
        return $this->clinic;
    }

    public function id(): ?int
    {
        return $this->clinic?->id;
    }

    public function has(): bool
    {
        return $this->clinic !== null;
    }

    /** The active clinic's timezone, or the installation's default when no clinic is active. */
    public function timezone(): string
    {
        return $this->clinic?->timezone ?: (string) config('dms.clinics.default_timezone', 'Asia/Dhaka');
    }

    public function require(): Clinic
    {
        return $this->clinic ?? throw new NoClinicResolved('No clinic has been resolved for this operation.');
    }
}
