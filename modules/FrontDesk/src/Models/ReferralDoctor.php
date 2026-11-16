<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReferralDoctor extends ClinicModel
{
    use Auditable;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'fd_referral_doctors';

    protected $fillable = ['name', 'degree', 'phone', 'is_active'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function auditName(): string
    {
        return 'referral_doctor';
    }
}
