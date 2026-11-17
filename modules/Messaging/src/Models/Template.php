<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;
use Modules\Messaging\Enums\Channel;

/**
 * @property string $template_key
 * @property Channel $channel
 * @property ?string $subject
 * @property string $body
 * @property bool $is_active
 */
class Template extends ClinicModel
{
    use Auditable;

    protected $table = 'msg_templates';

    protected $fillable = ['template_key', 'channel', 'subject', 'body', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['channel' => Channel::class, 'is_active' => 'boolean'];
    }

    public static function auditName(): string
    {
        return 'message_template';
    }
}
