<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

enum ClinicStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function isUsable(): bool
    {
        return $this === self::Trial || $this === self::Active;
    }
}
