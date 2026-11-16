<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\ServiceType;

/**
 * @property int $id
 * @property int $order_id
 * @property int $service_id
 * @property string $service_name
 * @property ServiceType $service_type
 * @property Money $unit_price
 * @property Money $discount_amount
 * @property Money $net_amount
 * @property ?int $package_item_of
 * @property ItemStatus $status
 */
class OrderItem extends ClinicModel
{
    protected $table = 'fd_order_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'unit_price' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            'status' => ItemStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** True for a test that came in as part of a package and has no price of its own. */
    public function isPackageComponent(): bool
    {
        return $this->package_item_of !== null;
    }

    public function isActive(): bool
    {
        return $this->status === ItemStatus::Active;
    }
}
