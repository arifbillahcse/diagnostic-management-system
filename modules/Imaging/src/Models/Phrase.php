<?php

declare(strict_types=1);

namespace Modules\Imaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

/**
 * @property string $title
 * @property string $body
 * @property bool $is_active
 */
class Phrase extends ClinicModel
{
    use Auditable;

    protected $table = 'img_phrases';

    protected $fillable = ['title', 'body', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function auditName(): string
    {
        return 'imaging_phrase';
    }
}
