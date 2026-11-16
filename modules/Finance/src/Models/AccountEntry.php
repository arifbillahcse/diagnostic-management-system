<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One movement of money. Written once; a correction is an opposite entry.
 *
 * @property int $account_id
 * @property Money $amount
 * @property string $source_type
 * @property int $source_id
 */
class AccountEntry extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'fin_account_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account entries cannot be changed. Post an opposite entry.'));
        static::deleting(fn () => throw new LogicException('Account entries cannot be deleted. Post an opposite entry.'));
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
