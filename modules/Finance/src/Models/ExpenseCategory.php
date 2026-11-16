<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

class ExpenseCategory extends ClinicModel
{
    use Auditable;

    protected $table = 'fin_expense_categories';

    protected $fillable = ['name', 'is_active'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function auditName(): string
    {
        return 'expense_category';
    }
}
