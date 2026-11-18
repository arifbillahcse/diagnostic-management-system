<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * What a read-only clinic may still do: look, print, and take its data
 * away. Every action in every module checks a permission, so refusing all
 * other permissions here stops every change in one place.
 */
final class ReadOnlyMode
{
    public function __construct(private readonly ClinicContext $clinics) {}

    public function on(): bool
    {
        return $this->clinics->get()?->status === ClinicStatus::ReadOnly;
    }

    public function allows(string $permission): bool
    {
        return str_ends_with($permission, '.view')
            || str_ends_with($permission, '.print')
            || str_ends_with($permission, '.print_with_due')
            || $permission === 'core.export.run';
    }
}
