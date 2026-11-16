<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

class ServiceCategory extends ClinicModel
{
    use Auditable;

    protected $table = 'fd_service_categories';

    protected $fillable = ['name', 'sort_order'];
}
