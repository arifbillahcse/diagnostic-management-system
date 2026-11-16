<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;

/**
 * Money given back to a patient. It affects the invoice only once approved.
 *
 * @property int $id
 * @property string $public_id
 * @property ?string $refund_no
 * @property int $invoice_id
 * @property Money $amount
 * @property PaymentMethod $method
 * @property RefundStatus $status
 * @property ?int $requested_by
 */
class Refund extends ClinicModel
{
    use HasPublicId;

    protected $table = 'fd_refunds';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
