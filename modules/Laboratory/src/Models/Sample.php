<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Auth\User;
use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\SampleStatus;

/**
 * One tube or container taken from a patient.
 *
 * @property int $id
 * @property string $public_id
 * @property string $accession_no
 * @property int $order_id
 * @property int $patient_id
 * @property int $sample_type_id
 * @property int $branch_id
 * @property SampleStatus $status
 * @property ?Carbon $collected_at
 */
class Sample extends ClinicModel
{
    use HasPublicId;

    protected $table = 'lab_samples';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => SampleStatus::class,
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(SampleType::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by')->withTrashed();
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SampleEvent::class)->orderBy('id');
    }
}
