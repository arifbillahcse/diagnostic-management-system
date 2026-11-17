<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Core\Tenancy\ClinicModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FrontDesk\Models\Order;

/**
 * @property string $token_hash
 * @property int $order_id
 * @property Carbon $expires_at
 * @property ?Carbon $revoked_at
 * @property int $opened_count
 */
class ReportLink extends ClinicModel
{
    protected $table = 'msg_report_links';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_opened_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
