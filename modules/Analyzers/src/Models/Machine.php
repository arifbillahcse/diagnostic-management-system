<?php

declare(strict_types=1);

namespace Modules\Analyzers\Models;

use App\Core\Auth\User;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property ?string $model
 * @property string $protocol
 * @property int $branch_id
 * @property int $user_id
 * @property string $token_hash
 * @property string $token_hint
 * @property bool $is_active
 * @property ?CarbonInterface $last_seen_at
 */
class Machine extends ClinicModel
{
    use HasPublicId;

    public const PROTOCOLS = ['astm' => 'ASTM (E1394)', 'hl7' => 'HL7 v2'];

    protected $table = 'anz_machines';

    protected $fillable = ['name', 'model', 'protocol', 'branch_id', 'user_id', 'is_active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(Mapping::class);
    }
}
