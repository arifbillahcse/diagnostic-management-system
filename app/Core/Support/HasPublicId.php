<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Str;

/**
 * Gives a model a random public identifier (ULID) for URLs and APIs, so the
 * numeric primary key never leaves the server.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    /** Links and route parameters carry the public ID, never the row number. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
