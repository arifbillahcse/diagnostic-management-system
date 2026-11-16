<?php

declare(strict_types=1);

namespace Modules\Laboratory\Models;

use App\Core\Tenancy\ClinicModel;
use LogicException;

/**
 * A verified set of results, kept for ever. An amendment adds a version;
 * it never replaces one.
 */
class ResultVersion extends ClinicModel
{
    public $timestamps = false;

    protected $table = 'lab_result_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verified result versions cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Verified result versions cannot be deleted.'));
    }
}
