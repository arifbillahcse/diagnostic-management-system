<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Laboratory\Enums\ResultType;

/**
 * One measured value, for example haemoglobin.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ResultType $result_type
 * @property ?string $unit
 * @property int $decimals
 * @property ?array $options
 * @property ?array $abnormal_options
 */
class Analyte extends ClinicModel
{
    use Auditable;

    protected $table = 'lab_analytes';

    protected $fillable = ['code', 'name', 'result_type', 'unit', 'decimals', 'options', 'abnormal_options', 'is_active'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'result_type' => ResultType::class,
            'options' => 'array',
            'abnormal_options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function ranges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class)->orderBy('sex')->orderBy('age_min_days');
    }
}
