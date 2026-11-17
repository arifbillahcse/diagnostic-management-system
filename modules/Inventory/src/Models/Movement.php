<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\QuantityCast;

/**
 * One change to stock. Written once; a correction is another movement.
 *
 * @property int $id
 * @property int $item_id
 * @property Quantity $quantity
 * @property Money $value
 * @property string $reason
 * @property ?string $batch_no
 * @property ?Carbon $expires_on
 * @property Carbon $occurred_at
 */
class Movement extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'inv_movements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'value' => MoneyCast::class,
            'expires_on' => 'date',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements cannot be changed. Record a correcting movement.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted. Record a correcting movement.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
