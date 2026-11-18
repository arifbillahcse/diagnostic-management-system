<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\Clinic;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A subscription invoice to a clinic. Only its status moves (issued →
 * paid, or issued → void); what it charges never changes.
 *
 * @property int $id
 * @property string $invoice_no
 * @property int $clinic_id
 * @property string $plan_name
 * @property string $billing_period
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property Money $amount
 * @property string $status
 * @property CarbonInterface $due_on
 */
class SubscriptionInvoice extends Model
{
    use HasPublicId;

    public const STATUSES = ['issued' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void'];

    protected $connection = 'control';

    protected $table = 'subscription_invoices';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (SubscriptionInvoice $invoice): void {
            foreach (['invoice_no', 'clinic_id', 'plan_name', 'billing_period', 'period_start', 'period_end', 'amount', 'due_on'] as $key) {
                if ($invoice->isDirty($key) && self::normal($key, $invoice->getRawOriginal($key)) !== self::normal($key, $invoice->getAttributes()[$key] ?? null)) {
                    throw new LogicException('An invoice is never changed. Void it and issue a new one.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Invoices are never deleted. Void it instead.'));
    }

    /** The same value written differently ("3000" and "3000.00") is not a change. */
    private static function normal(string $key, mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $key === 'amount' => Money::fromDecimal((string) $value)->toDecimal(),
            in_array($key, ['period_start', 'period_end', 'due_on'], true) => substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class, 'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date',
            'paid_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'invoice_id');
    }

    public static function color(string $status): string
    {
        return ['issued' => 'warning', 'paid' => 'success', 'void' => 'gray'][$status] ?? 'gray';
    }
}
