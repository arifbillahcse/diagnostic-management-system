<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FrontDesk\Models\Service;

/**
 * The laboratory's side of a billable lab test: which sample it needs and
 * which values it reports.
 *
 * @property int $id
 * @property int $service_id
 * @property int $sample_type_id
 * @property ?string $department
 */
class LabTest extends ClinicModel
{
    use Auditable;

    protected $table = 'lab_tests';

    protected $fillable = ['service_id', 'sample_type_id', 'department', 'turnaround_hours', 'method', 'report_notes'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(SampleType::class);
    }

    /** The values this test reports, in report order. */
    public function analytes(): BelongsToMany
    {
        return $this->belongsToMany(Analyte::class, 'lab_test_analytes')
            ->withPivot(['sort_order', 'group_label', 'is_required'])
            ->orderBy('lab_test_analytes.sort_order')
            ->orderBy('lab_test_analytes.id');
    }

    public function analyteLinks(): HasMany
    {
        return $this->hasMany(TestAnalyte::class)->orderBy('sort_order')->orderBy('id');
    }

    public static function auditName(): string
    {
        return 'lab_test';
    }
}
