<?php

declare(strict_types=1);

namespace Modules\Analyzers\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Laboratory\Models\Analyte;

/**
 * @property int $machine_id
 * @property string $machine_code
 * @property int $analyte_id
 */
class Mapping extends ClinicModel
{
    use Auditable;

    protected $table = 'anz_mappings';

    protected $fillable = ['machine_id', 'machine_code', 'analyte_id'];

    public static function auditName(): string
    {
        return 'analyzer_mapping';
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function analyte(): BelongsTo
    {
        return $this->belongsTo(Analyte::class);
    }
}
