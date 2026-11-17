<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Core\Patients\Patient;
use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\Order;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;

/**
 * @property int $id
 * @property string $public_id
 * @property Channel $channel
 * @property string $recipient
 * @property string $template_key
 * @property ?string $subject
 * @property string $body
 * @property int $segments
 * @property MessageStatus $status
 * @property ?string $provider
 * @property ?string $provider_ref
 * @property ?string $error
 * @property int $attempts
 * @property ?string $dedupe_key
 * @property ?int $patient_id
 * @property ?int $order_id
 * @property ?Carbon $sent_at
 */
class Message extends ClinicModel
{
    use HasPublicId;

    protected $table = 'msg_messages';

    protected $guarded = ['id', 'public_id'];

    protected $attributes = ['attempts' => 0, 'segments' => 1];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
