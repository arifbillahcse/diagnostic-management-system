<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One person in a salary run. It can change only while the run is a draft.
 *
 * @property int $id
 * @property int $run_id
 * @property int $staff_id
 * @property Money $monthly_salary
 * @property Money $gross
 * @property Money $deduction
 * @property ?string $deduction_note
 * @property Money $advance_recovery
 * @property Money $net
 */
class SalaryLine extends ClinicModel
{
    use HasPublicId;

    protected $table = 'ppl_salary_lines';

    protected $fillable = [
        'run_id', 'staff_id', 'monthly_salary', 'days_in_month', 'days_employed', 'days_worked', 'days_absent', 'days_leave',
        'days_off', 'days_unmarked', 'gross', 'deduction', 'deduction_note', 'advance_recovery', 'net',
    ];

    protected static function booted(): void
    {
        $guard = function (SalaryLine $line): void {
            if (SalaryRun::whereKey($line->run_id)->value('status') !== 'draft') {
                throw new LogicException('This salary run is no longer a draft, so its lines cannot change.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'monthly_salary' => MoneyCast::class, 'gross' => MoneyCast::class, 'deduction' => MoneyCast::class,
            'advance_recovery' => MoneyCast::class, 'net' => MoneyCast::class, 'days_worked' => 'decimal:1',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SalaryRun::class, 'run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
