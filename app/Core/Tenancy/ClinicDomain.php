<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicDomain extends Model
{
    protected $connection = 'control';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public static function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
