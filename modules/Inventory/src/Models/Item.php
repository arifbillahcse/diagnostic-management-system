<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\QuantityCast;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string $unit
 * @property Quantity $reorder_level
 * @property bool $tracks_expiry
 * @property bool $is_active
 * @property Quantity $quantity
 * @property Money $stock_value
 */
class Item extends ClinicModel
{
    use Auditable;
    use HasPublicId;

    protected $table = 'inv_items';

    /** Stock on hand changes only through the stock ledger. */
    protected $fillable = ['code', 'name', 'category', 'unit', 'reorder_level', 'tracks_expiry', 'is_active'];

    protected $attributes = ['is_active' => true, 'tracks_expiry' => false, 'reorder_level' => '0.000', 'quantity' => '0.000', 'stock_value' => '0.00'];

    protected function casts(): array
    {
        return [
            'reorder_level' => QuantityCast::class,
            'quantity' => QuantityCast::class,
            'stock_value' => MoneyCast::class,
            'tracks_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function auditName(): string
    {
        return 'stock_item';
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    /** The value of one unit at today's average cost. */
    public function averageCost(): Money
    {
        return Quantity::fromMilli(1000)->shareOf($this->stock_value, $this->quantity);
    }

    /** "out", "low", or "ok". */
    public function level(): string
    {
        return match (true) {
            ! $this->quantity->isPositive() => 'out',
            ! $this->quantity->greaterThan($this->reorder_level) => 'low',
            default => 'ok',
        };
    }
}
