<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Tenancy\ClinicModel;
use LogicException;

/**
 * One entry in the audit trail. Entries are written once and never changed.
 */
class AuditLog extends ClinicModel
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }
}
