<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\FrontDesk\Enums\OrderStatus;

/**
 * One patient visit: the services ordered and, through its invoice, the money.
 *
 * @property int $id
 * @property string $public_id
 * @property string $order_no
 * @property int $patient_id
 * @property int $branch_id
 * @property OrderStatus $status
 */
class Order extends ClinicModel
{
    use HasPublicId;

    protected $table = 'fd_orders';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'ordered_at' => 'datetime',
            'delivery_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function referralDoctor(): BelongsTo
    {
        return $this->belongsTo(ReferralDoctor::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
