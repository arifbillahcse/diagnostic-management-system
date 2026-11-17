<?php

declare(strict_types=1);

namespace Modules\People\Models;

use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member on one day. Written only by RecordAttendance.
 *
 * @property int $staff_id
 * @property CarbonInterface $date
 * @property string $status
 * @property ?string $in_time
 * @property ?string $out_time
 * @property ?string $note
 */
class Attendance extends ClinicModel
{
    protected $table = 'ppl_attendance';

    protected $fillable = ['staff_id', 'date', 'status', 'in_time', 'out_time', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
