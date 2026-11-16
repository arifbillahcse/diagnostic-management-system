<?php

declare(strict_types=1);

namespace App\Core\Patients;

final class PatientRegistered
{
    public function __construct(public readonly Patient $patient) {}
}
