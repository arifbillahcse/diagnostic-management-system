<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestAnalyte extends ClinicModel
{
    public $timestamps = false;

    protected $table = 'lab_test_analytes';

    protected $fillable = ['lab_test_id', 'analyte_id', 'sort_order', 'group_label', 'is_required'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function analyte(): BelongsTo
    {
        return $this->belongsTo(Analyte::class);
    }
}
