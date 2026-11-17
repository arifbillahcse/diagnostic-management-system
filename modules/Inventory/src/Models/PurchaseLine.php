<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\QuantityCast;

/**
 * One item on a purchase. Written once.
 *
 * @property int $id
 * @property int $purchase_id
 * @property int $item_id
 * @property Quantity $quantity
 * @property Money $unit_cost
 * @property Money $line_total
 * @property ?string $batch_no
 * @property ?Carbon $expires_on
 */
class PurchaseLine extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'inv_purchase_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_cost' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'expires_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase lines cannot be changed. Void the purchase and enter it again.'));
        static::deleting(fn () => throw new LogicException('Purchase lines cannot be deleted. Void the purchase.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
