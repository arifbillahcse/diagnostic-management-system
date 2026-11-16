<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\ReferralDoctor;

/**
 * @property string $payout_no
 * @property Money $amount
 */
class CommissionPayout extends ClinicModel
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'fin_commission_payouts';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'paid_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ReferralDoctor::class, 'referral_doctor_id')->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
