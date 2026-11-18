<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Money received for a subscription invoice. Append-only.
 *
 * @property int $invoice_id
 * @property Money $amount
 * @property string $method
 * @property ?string $reference
 * @property CarbonInterface $received_on
 */
class SubscriptionPayment extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'];

    protected $connection = 'control';

    protected $table = 'subscription_payments';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A recorded payment is never changed.'));
        static::deleting(fn () => throw new LogicException('A recorded payment is never deleted.'));
    }

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'received_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }
}
