<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use LogicException;
use Modules\FrontDesk\Enums\LineKind;

/**
 * One line of an invoice. Written once; a later change is another line.
 *
 * @property LineKind $kind
 * @property Money $amount
 */
class InvoiceLine extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'fd_invoice_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'amount' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines cannot be changed. Add an adjustment line.'));
        static::deleting(fn () => throw new LogicException('Invoice lines cannot be deleted. Add an adjustment line.'));
    }
}
