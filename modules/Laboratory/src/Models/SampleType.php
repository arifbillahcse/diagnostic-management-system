<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

class SampleType extends ClinicModel
{
    use Auditable;

    protected $table = 'lab_sample_types';

    protected $fillable = ['name', 'container', 'label_copies'];

    public static function auditName(): string
    {
        return 'sample_type';
    }
}
