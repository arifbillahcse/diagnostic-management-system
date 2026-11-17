<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Audit\Auditable;
use App\Core\Auth\User;
use App\Core\Tenancy\ClinicModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a person who signs reports (pathologist, sonologist, radiologist) is
 * named on paper: printed name, lines under it, and a signature image.
 *
 * @property int $user_id
 * @property string $display_name
 * @property ?string $qualifications
 * @property ?string $signature_path
 */
class Signatory extends ClinicModel
{
    use Auditable;

    protected $table = 'signatories';

    protected $fillable = ['user_id', 'display_name', 'qualifications', 'signature_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return list<string> */
    public function qualificationLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->qualifications) ?: [])));
    }
}
