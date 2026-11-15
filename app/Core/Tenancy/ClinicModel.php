<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for every model stored in a clinic database.
 */
abstract class ClinicModel extends Model
{
    use UsesClinicConnection;
}
