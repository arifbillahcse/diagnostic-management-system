<?php

declare(strict_types=1);

namespace Modules\People\Support;

use App\Core\Money\Money;
use Modules\People\Models\Advance;
use Modules\People\Models\SalaryLine;

/**
 * How much advance a staff member still owes: every advance that is not
 * void, less what approved or paid salary runs have taken back.
 */
final class AdvanceBook
{
    public function given(int $staffId): Money
    {
        return Money::sum(...Advance::where('staff_id', $staffId)->whereNull('voided_at')->get()->map->amount->all());
    }

    public function recovered(int $staffId): Money
    {
        return Money::sum(...SalaryLine::where('staff_id', $staffId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->get()->map->advance_recovery->all());
    }

    public function outstanding(int $staffId): Money
    {
        return $this->given($staffId)->minus($this->recovered($staffId));
    }
}
