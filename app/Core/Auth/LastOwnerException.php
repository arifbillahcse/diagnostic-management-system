<?php

declare(strict_types=1);

namespace App\Core\Auth;

use DomainException;

class LastOwnerException extends DomainException
{
    public function __construct()
    {
        parent::__construct('A clinic must always have at least one active owner.');
    }
}
