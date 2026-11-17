<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\Service;
use Modules\Inventory\Support\Quantity;
use Modules\Inventory\Support\QuantityCast;

/**
 * "Each CBC uses 1 EDTA tube": what a test or service takes out of stock.
 *
 * @property int $service_id
 * @property int $item_id
 * @property Quantity $quantity
 */
class Usage extends ClinicModel
{
    use Auditable;

    protected $table = 'inv_usages';

    protected $fillable = ['service_id', 'item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => QuantityCast::class];
    }

    public static function auditName(): string
    {
        return 'stock_use_rule';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
