<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Enums\CashSessionStatus;

/**
 * One person's cash drawer for one sitting: opened with a float, closed
 * with a count.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $opened_by
 * @property Money $opening_float
 * @property ?Money $expected_cash
 * @property ?Money $counted_cash
 * @property ?Money $difference
 * @property CashSessionStatus $status
 */
class CashSession extends ClinicModel
{
    use HasPublicId;

    protected $table = 'fd_cash_sessions';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'opening_float' => MoneyCast::class,
            'expected_cash' => MoneyCast::class,
            'counted_cash' => MoneyCast::class,
            'difference' => MoneyCast::class,
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }
}
