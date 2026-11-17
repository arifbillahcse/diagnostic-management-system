<?php

declare(strict_types=1);

namespace Modules\Imaging\Models;

use App\Core\Auth\User;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A signed imaging report. Append-only: a correction is a new version.
 *
 * @property int $study_id
 * @property int $version
 * @property string $findings
 * @property string $impression
 * @property ?string $advice
 * @property ?int $written_by
 * @property int $signed_by
 * @property CarbonInterface $signed_at
 * @property ?string $amend_reason
 */
class ReportVersion extends ClinicModel
{
    public const UPDATED_AT = null;

    protected $table = 'img_report_versions';

    protected $fillable = ['study_id', 'version', 'findings', 'impression', 'advice', 'template_id', 'written_by', 'signed_by', 'signed_at', 'amend_reason'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A signed imaging report cannot be changed. Amend it to make a new version.'));
        static::deleting(fn () => throw new LogicException('A signed imaging report cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by')->withTrashed();
    }
}
