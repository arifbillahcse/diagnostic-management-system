<?php

declare(strict_types=1);

namespace Modules\Analyzers\Models;

use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Laboratory\Models\Analyte;

/**
 * One value from a machine message and what became of it.
 *
 * @property int $message_id
 * @property ?string $sample_code
 * @property string $machine_code
 * @property string $value
 * @property ?string $unit
 * @property ?string $machine_flag
 * @property ?int $analyte_id
 * @property ?int $work_item_id
 * @property string $status
 * @property ?string $note
 */
class MachineResult extends ClinicModel
{
    public const STATUSES = [
        'applied' => 'Saved as draft',
        'unmapped' => 'Code not mapped',
        'no_sample' => 'No sample with this number',
        'not_received' => 'Sample not received in the lab',
        'no_test' => 'Test not ordered on this sample',
        'already_submitted' => 'Results already submitted',
        'failed' => 'Not saved',
    ];

    protected $table = 'anz_results';

    protected $fillable = ['message_id', 'sample_code', 'machine_code', 'value', 'unit', 'machine_flag', 'analyte_id', 'work_item_id', 'status', 'note'];

    public function analyte(): BelongsTo
    {
        return $this->belongsTo(Analyte::class);
    }
}
