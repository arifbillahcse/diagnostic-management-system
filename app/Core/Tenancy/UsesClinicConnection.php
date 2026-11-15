<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Pins a model to the current clinic's database.
 */
trait UsesClinicConnection
{
    public function getConnectionName(): string
    {
        return 'clinic';
    }
}
