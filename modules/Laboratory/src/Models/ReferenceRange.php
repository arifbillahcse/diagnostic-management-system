<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

/**
 * @property int $analyte_id
 * @property string $sex
 * @property int $age_min_days
 * @property int $age_max_days
 * @property ?string $low
 * @property ?string $high
 * @property ?string $critical_low
 * @property ?string $critical_high
 * @property ?string $text_range
 */
class ReferenceRange extends ClinicModel
{
    use Auditable;

    public const MAX_AGE_DAYS = 54750;

    protected $table = 'lab_reference_ranges';

    protected $fillable = [
        'analyte_id', 'sex', 'age_min_days', 'age_max_days', 'low', 'high',
        'critical_low', 'critical_high', 'text_range', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public static function auditName(): string
    {
        return 'reference_range';
    }
}
