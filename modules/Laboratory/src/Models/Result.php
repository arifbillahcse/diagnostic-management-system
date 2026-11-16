<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Tenancy\ClinicModel;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\ResultType;

/**
 * The current value of one analyte for one test.
 *
 * @property int $work_item_id
 * @property int $analyte_id
 * @property string $analyte_name
 * @property ResultType $result_type
 * @property ?string $value_numeric
 * @property ?string $value_text
 * @property ?string $unit
 * @property ?string $ref_low
 * @property ?string $ref_high
 * @property ?string $ref_text
 * @property Flag $flag
 */
class Result extends ClinicModel
{
    protected $table = 'lab_results';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result_type' => ResultType::class,
            'flag' => Flag::class,
        ];
    }

    /** The value as printed: a number without trailing zeros noise, or the text. */
    public function display(): string
    {
        return (string) ($this->value_text ?? '');
    }

    /** The reference range as printed. */
    public function rangeDisplay(): string
    {
        return (string) ($this->ref_text ?? '');
    }
}
