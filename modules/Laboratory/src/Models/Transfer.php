<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Samples sent together from a collection centre to a laboratory. Its
 * status changes only through HandleSample: in_transit → received, when
 * its last sample is received or rejected.
 *
 * @property int $id
 * @property string $transfer_no
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property string $status
 * @property CarbonInterface $sent_at
 * @property ?CarbonInterface $received_at
 */
class Transfer extends ClinicModel
{
    use HasPublicId;

    protected $table = 'lab_transfers';

    protected $fillable = ['transfer_no', 'from_branch_id', 'to_branch_id', 'status', 'note', 'sent_by', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by')->withTrashed();
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'transfer_id');
    }
}
