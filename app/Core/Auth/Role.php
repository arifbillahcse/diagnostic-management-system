<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_system
 */
class Role extends ClinicModel
{
    /** The owner role holds every permission and cannot be edited or removed. */
    public const OWNER = 'owner';

    protected $fillable = ['key', 'name', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot('branch_id');
    }

    public function isOwner(): bool
    {
        return $this->key === self::OWNER;
    }
}
