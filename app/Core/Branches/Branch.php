<?php

declare(strict_types=1);

namespace App\Core\Branches;

use App\Core\Audit\Auditable;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class Branch extends ClinicModel
{
    use Auditable;
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
