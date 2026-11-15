<?php

declare(strict_types=1);

namespace App\Core\Audit;

/**
 * Records create, update, and delete of a simple model in the audit trail.
 *
 * Use it for plain master data. Operations with their own rules (payments,
 * results, role changes) write a purpose-made entry from their action class.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            app(AuditLogger::class)->record(static::auditName().'.created', $model, [], $model->getAttributes());
        });

        static::updated(function ($model): void {
            $changes = $model->getChanges();
            $before = array_intersect_key($model->getOriginal(), $changes);
            [$before, $after] = app(AuditLogger::class)->diff($before, $changes);

            if ($after !== []) {
                app(AuditLogger::class)->record(static::auditName().'.updated', $model, $before, $after);
            }
        });

        static::deleted(function ($model): void {
            app(AuditLogger::class)->record(static::auditName().'.deleted', $model, $model->getOriginal(), []);
        });
    }

    /** Prefix of the audit action, for example "branch". */
    public static function auditName(): string
    {
        return strtolower(class_basename(static::class));
    }
}
