<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinic, stored in the control database.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ClinicStatus $status
 * @property string $db_name
 * @property ?string $db_host
 * @property ?string $db_port
 * @property ?string $db_username
 * @property ?string $db_password
 * @property string $timezone
 * @property ?int $plan_id
 */
class Clinic extends Model
{
    use HasPublicId;

    protected $connection = 'control';

    protected $guarded = ['id'];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return [
            'status' => ClinicStatus::class,
            'db_password' => 'encrypted',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ClinicDomain::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
