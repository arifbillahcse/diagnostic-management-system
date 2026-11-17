<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use LogicException;

/**
 * One step of a staff member's salary history. Append-only.
 *
 * @property int $staff_id
 * @property Money $monthly_salary
 * @property CarbonInterface $effective_from
 */
class SalaryChange extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'ppl_salary_changes';

    protected $fillable = ['staff_id', 'monthly_salary', 'effective_from', 'reason', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Salary history cannot be changed. Record a new salary change.'));
        static::deleting(fn () => throw new LogicException('Salary history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['monthly_salary' => MoneyCast::class, 'effective_from' => 'date'];
    }
}
