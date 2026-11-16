<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use LogicException;

/**
 * Commission earned or taken back for one order item. Written once.
 *
 * @property Money $amount
 */
class CommissionEntry extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'fin_commission_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Commission entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Commission entries cannot be deleted.'));
    }
}
