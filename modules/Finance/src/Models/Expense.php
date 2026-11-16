<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Auth\User;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $expense_no
 * @property int $account_id
 * @property int $branch_id
 * @property Money $amount
 * @property Carbon $expense_date
 * @property ?Carbon $voided_at
 */
class Expense extends ClinicModel
{
    use HasPublicId;

    protected $table = 'fin_expenses';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'expense_date' => 'date', 'voided_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }
}
