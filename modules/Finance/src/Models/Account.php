<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FrontDesk\Enums\PaymentMethod;

/**
 * A place money is held. Its type is one of the payment methods, so money
 * received by a method lands in an account of the same type.
 *
 * @property int $id
 * @property string $name
 * @property PaymentMethod $type
 * @property bool $is_default
 * @property bool $is_active
 */
class Account extends ClinicModel
{
    use Auditable;
    use HasPublicId;

    protected $table = 'fin_accounts';

    protected $fillable = ['name', 'type', 'is_default', 'is_active'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true, 'is_default' => false];

    protected function casts(): array
    {
        return [
            'type' => PaymentMethod::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccountEntry::class);
    }
}
