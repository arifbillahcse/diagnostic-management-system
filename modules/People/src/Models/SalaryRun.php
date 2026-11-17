<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's salaries for one branch. Status changes only through
 * RunPayroll: draft → approved → paid; draft or approved → cancelled.
 *
 * @property int $id
 * @property string $run_no
 * @property int $branch_id
 * @property string $month
 * @property string $status
 * @property Money $total_gross
 * @property Money $total_deductions
 * @property Money $total_advances
 * @property Money $total_net
 */
class SalaryRun extends ClinicModel
{
    use HasPublicId;

    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    /** Allowed moves of the run state machine. */
    public const TRANSITIONS = ['draft' => ['approved', 'cancelled'], 'approved' => ['paid', 'cancelled'], 'paid' => [], 'cancelled' => []];

    protected $table = 'ppl_salary_runs';

    protected $fillable = ['run_no', 'branch_id', 'month', 'status', 'live_key', 'created_by'];

    protected function casts(): array
    {
        return [
            'total_gross' => MoneyCast::class, 'total_deductions' => MoneyCast::class,
            'total_advances' => MoneyCast::class, 'total_net' => MoneyCast::class,
            'approved_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'paid_on' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryLine::class, 'run_id');
    }

    public function monthLabel(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->format('F Y');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Approved or paid: the month is settled for this branch. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }
}
