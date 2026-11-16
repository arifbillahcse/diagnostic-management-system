<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Branches\Branch;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\UsesClinicConnection;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A clinic staff member. Lives in the clinic database.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property ?int $default_branch_id
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasPublicId;
    use Notifiable;
    use SoftDeletes;
    use UsesClinicConnection;

    protected $fillable = ['name', 'email', 'phone', 'password', 'is_active', 'default_branch_id'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** Defaults for a record made in code, matching the database defaults. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('branch_id')->withTimestamps();
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains('key', $key);
    }

    // Two-step sign-in with an authenticator app (optional, per user).

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();

        app(AuditLogger::class)->record($secret === null ? 'user.two_step_disabled' : 'user.two_step_enabled', $this);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasTwoStepSignIn(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }
}
