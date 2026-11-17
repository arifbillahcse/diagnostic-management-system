<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Auth\User;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\QuantityCast;

/**
 * Stock a test used that was not on hand.
 *
 * @property int $order_item_id
 * @property int $item_id
 * @property Quantity $quantity
 * @property ?Carbon $resolved_at
 */
class Shortfall extends ClinicModel
{
    protected $table = 'inv_shortfalls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => QuantityCast::class, 'resolved_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }
}
