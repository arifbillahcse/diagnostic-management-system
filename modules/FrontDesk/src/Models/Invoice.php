<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FrontDesk\Enums\InvoiceStatus;

/**
 * @property int $id
 * @property string $public_id
 * @property string $invoice_no
 * @property int $order_id
 * @property int $patient_id
 * @property int $branch_id
 * @property Money $subtotal
 * @property Money $discount_total
 * @property Money $adjustment_total
 * @property Money $total
 * @property Money $paid_total
 * @property Money $refunded_total
 * @property Money $due_amount
 * @property InvoiceStatus $status
 */
class Invoice extends ClinicModel
{
    use HasPublicId;

    protected $table = 'fd_invoices';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'adjustment_total' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid_total' => MoneyCast::class,
            'refunded_total' => MoneyCast::class,
            'due_amount' => MoneyCast::class,
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** Money received and not given back. */
    public function netPaid(): Money
    {
        return $this->paid_total->minus($this->refunded_total);
    }

    /** What the clinic owes the patient when more was paid than is now charged. */
    public function credit(): Money
    {
        return $this->due_amount->isNegative() ? $this->due_amount->negate() : Money::zero();
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }
}
