<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Tenancy\ClinicModel;

/**
 * @property int $id
 * @property string $key
 * @property string $module_key
 * @property ?string $description
 */
class Permission extends ClinicModel
{
    protected $fillable = ['key', 'module_key', 'description'];
}
