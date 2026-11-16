<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Tenancy\ClinicModel;

class SampleEvent extends ClinicModel
{
    public $timestamps = false;

    protected $table = 'lab_sample_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
