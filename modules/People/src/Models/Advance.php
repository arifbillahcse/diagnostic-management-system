<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given to a staff member ahead of salary, recovered from salary runs.
 * Written only by ManageAdvances; never edited, only voided.
 *
 * @property int $id
 * @property string $advance_no
 * @property int $staff_id
 * @property int $branch_id
 * @property Money $amount
 * @property CarbonInterface $given_on
 * @property ?CarbonInterface $voided_at
 */
class Advance extends ClinicModel
{
    use HasPublicId;

    protected $table = 'ppl_advances';

    protected $fillable = ['advance_no', 'staff_id', 'branch_id', 'amount', 'given_on', 'reason', 'expense_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'given_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }
}
