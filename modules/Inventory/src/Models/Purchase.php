<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $purchase_no
 * @property int $supplier_id
 * @property int $branch_id
 * @property Carbon $purchase_date
 * @property ?string $supplier_bill_no
 * @property Money $total
 * @property ?Carbon $paid_at
 * @property ?int $expense_id
 * @property ?Carbon $voided_at
 */
class Purchase extends ClinicModel
{
    use HasPublicId;

    protected $table = 'inv_purchases';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'total' => MoneyCast::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withTrashed();
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** "void", "paid", or "unpaid". */
    public function state(): string
    {
        return match (true) {
            $this->isVoid() => 'void',
            $this->isPaid() => 'paid',
            default => 'unpaid',
        };
    }
}
