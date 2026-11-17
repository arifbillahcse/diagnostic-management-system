<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
class Supplier extends ClinicModel
{
    use Auditable;
    use HasPublicId;

    protected $table = 'inv_suppliers';

    protected $fillable = ['name', 'phone', 'address', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
