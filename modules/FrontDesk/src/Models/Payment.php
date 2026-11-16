<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\FrontDesk\Enums\PaymentMethod;

/**
 * Money received against an invoice. Written once; a mistake is corrected
 * with a refund.
 *
 * @property int $id
 * @property string $public_id
 * @property string $receipt_no
 * @property int $invoice_id
 * @property Money $amount
 * @property PaymentMethod $method
 */
class Payment extends ClinicModel
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'fd_payments';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments cannot be changed. Issue a refund.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted. Issue a refund.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }
}
