<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Raised whenever the active clinic changes (including to "none").
 * Services that keep per-clinic state in memory clear it on this event.
 */
final class ClinicSwitched
{
    public function __construct(public readonly ?Clinic $clinic) {}
}
