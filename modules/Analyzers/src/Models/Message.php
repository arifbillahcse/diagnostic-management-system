<?php

declare(strict_types=1);

namespace Modules\Analyzers\Models;

use App\Core\Support\HasPublicId;
use App\Core\Tenancy\ClinicModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One message from a machine, kept exactly as it arrived.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $protocol
 * @property string $raw
 * @property string $status
 * @property ?string $sample_codes
 * @property ?string $note
 * @property CarbonInterface $received_at
 */
class Message extends ClinicModel
{
    use HasPublicId;

    public const STATUSES = ['applied' => 'Saved', 'partial' => 'Partly saved', 'unmatched' => 'Not matched', 'failed' => 'Could not read'];

    protected $table = 'anz_messages';

    protected $fillable = ['machine_id', 'protocol', 'raw', 'raw_hash', 'status', 'sample_codes', 'note', 'received_at'];

    protected static function booted(): void
    {
        static::updating(function (Message $message): void {
            if ($message->isDirty(['raw', 'raw_hash', 'machine_id', 'protocol', 'received_at'])) {
                throw new LogicException('A machine message is kept as it arrived and cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Machine messages are never deleted.'));
    }

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(MachineResult::class, 'message_id');
    }

    public static function color(string $status): string
    {
        return ['applied' => 'success', 'partial' => 'warning', 'unmatched' => 'danger', 'failed' => 'danger'][$status] ?? 'gray';
    }
}
