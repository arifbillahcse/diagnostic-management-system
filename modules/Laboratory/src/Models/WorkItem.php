<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Auth\User;
use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;
use Modules\Laboratory\Enums\WorkStatus;

/**
 * One test to be performed for one order. Carries the result state machine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_item_id
 * @property int $order_id
 * @property int $lab_test_id
 * @property ?int $sample_id
 * @property int $patient_id
 * @property int $branch_id
 * @property string $test_name
 * @property WorkStatus $status
 * @property ?int $entered_by
 * @property ?int $verified_by
 * @property int $version
 * @property int $lock_version
 */
class WorkItem extends ClinicModel
{
    use HasPublicId;

    protected $table = 'lab_work_items';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => WorkStatus::class,
            'entered_at' => 'datetime',
            'verified_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function enterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by')->withTrashed();
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class)->orderBy('sort_order')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ResultVersion::class)->orderBy('version');
    }

    /** The branch whose laboratory tests it: a collection centre sends its samples elsewhere. */
    public function labBranchId(): int
    {
        return (int) ($this->lab_branch_id ?? $this->branch_id);
    }

    /** Tested at another branch than the one that ordered it. */
    public function isSentAway(): bool
    {
        return $this->labBranchId() !== (int) $this->branch_id;
    }
}
