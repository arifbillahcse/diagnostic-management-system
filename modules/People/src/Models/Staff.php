<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff member. Written only by the ManageStaff action, which audits
 * every change; the salary changes only through a salary change row.
 *
 * @property int $id
 * @property string $staff_no
 * @property string $name
 * @property string $designation
 * @property int $branch_id
 * @property ?int $user_id
 * @property CarbonInterface $joined_on
 * @property ?CarbonInterface $left_on
 * @property Money $monthly_salary
 */
class Staff extends ClinicModel
{
    use HasPublicId;

    protected $table = 'ppl_staff';

    protected $fillable = ['staff_no', 'name', 'designation', 'phone', 'branch_id', 'user_id', 'joined_on', 'left_on', 'leave_reason', 'monthly_salary', 'notes'];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'left_on' => 'date', 'monthly_salary' => MoneyCast::class];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function salaryChanges(): HasMany
    {
        return $this->hasMany(SalaryChange::class)->orderBy('effective_from')->orderBy('id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function hasLeft(): bool
    {
        return $this->left_on !== null;
    }

    /** Employed on this day: joined on or before it, and not left before it. */
    public function worksOn(string $date): bool
    {
        return $this->joined_on->toDateString() <= $date
            && ($this->left_on === null || $this->left_on->toDateString() >= $date);
    }

    /** @param Builder<Staff> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('left_on');
    }

    /** People employed at some point between the two dates. @param Builder<Staff> $query */
    public function scopeEmployedBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('joined_on', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhereDate('left_on', '>=', $from));
    }

    /** The salary that applied on a day, from the salary history. */
    public function salaryOn(string $date): Money
    {
        $change = $this->salaryChanges()->whereDate('effective_from', '<=', $date)->reorder('effective_from', 'desc')->orderByDesc('id')->first();

        // Before the first change (the joining date was moved earlier), the first salary applies.
        $change ??= $this->salaryChanges()->first();

        return $change?->monthly_salary ?? Money::zero();
    }
}
