<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Audit\Auditable;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\FrontDesk\Enums\ServiceType;

/**
 * Anything a clinic bills for: a lab test, an imaging study, a package of
 * tests, or another charge.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ServiceType $type
 * @property Money $price
 * @property bool $is_active
 */
class Service extends ClinicModel
{
    use Auditable;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'fd_services';

    protected $fillable = ['code', 'name', 'type', 'category_id', 'price', 'is_active'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'price' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /** The services inside this package. Empty unless the type is "package". */
    public function packageItems(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'fd_package_items', 'package_service_id', 'service_id');
    }

    public function isPackage(): bool
    {
        return $this->type === ServiceType::Package;
    }
}
