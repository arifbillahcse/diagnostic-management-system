<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\Service;

/**
 * @property int $referral_doctor_id
 * @property ?int $service_id
 * @property string $kind
 * @property string $value
 */
class CommissionRule extends ClinicModel
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $table = 'fin_commission_rules';

    protected $fillable = ['referral_doctor_id', 'service_id', 'kind', 'value'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** The value as plain decimal text, whatever the database returned. */
    public function valueText(): string
    {
        return number_format((float) $this->value, 2, '.', '');
    }
}
