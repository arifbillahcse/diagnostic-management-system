<?php

declare(strict_types=1);

namespace Modules\Imaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Support\Html;

/**
 * Ready-made report text for one imaging service. The HTML is cleaned on
 * every save, so only simple formatting reaches a printed report.
 *
 * @property int $id
 * @property int $service_id
 * @property string $name
 * @property string $sex
 * @property string $findings
 * @property string $impression
 * @property ?string $advice
 * @property bool $is_active
 */
class Template extends ClinicModel
{
    use Auditable;
    use HasPublicId;

    protected $table = 'img_templates';

    protected $fillable = ['service_id', 'name', 'sex', 'findings', 'impression', 'advice', 'sort_order', 'is_active'];

    protected $attributes = ['sex' => 'any', 'is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (Template $template): void {
            $template->findings = Html::clean($template->findings);
            $template->impression = Html::clean($template->impression);
            $template->advice = filled($template->advice) ? trim((string) $template->advice) : null;
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function auditName(): string
    {
        return 'imaging_template';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** Suits a patient of this sex ("male", "female", or anything else). */
    public function suits(?string $sex): bool
    {
        return $this->sex === 'any' || $this->sex === $sex;
    }
}
