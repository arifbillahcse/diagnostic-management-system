<?php

declare(strict_types=1);

namespace Modules\Demo\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\ClinicModel;

/**
 * @property int $id
 * @property string $title
 * @property ?string $body
 */
class DemoNote extends ClinicModel
{
    use Auditable;

    protected $table = 'demo_notes';

    protected $fillable = ['title', 'body'];
}
