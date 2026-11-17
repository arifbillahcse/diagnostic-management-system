<?php

declare(strict_types=1);

namespace Modules\Imaging\Models;

use App\Core\Auth\User;
use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\OrderItem;

/**
 * One billed imaging test. Its status changes only through WriteReport and
 * the order listeners; the signed text is in ReportVersion.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $patient_id
 * @property int $branch_id
 * @property int $service_id
 * @property string $study_name
 * @property string $status
 * @property int $version
 * @property ?string $draft_findings
 * @property ?string $draft_impression
 * @property ?string $draft_advice
 * @property ?int $draft_template_id
 * @property ?int $draft_by
 * @property ?string $amend_reason
 * @property ?int $signed_by
 * @property ?CarbonInterface $signed_at
 * @property int $lock_version
 */
class Study extends ClinicModel
{
    use HasPublicId;

    protected $table = 'img_studies';

    protected $fillable = ['order_item_id', 'order_id', 'patient_id', 'branch_id', 'service_id', 'study_name', 'status'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
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

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class)->orderBy('version');
    }

    public function latestVersion(): ?ReportVersion
    {
        return $this->versions()->reorder('version', 'desc')->first();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['pending', 'draft', 'amending'], true);
    }
}
