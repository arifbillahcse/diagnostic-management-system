<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Core\Tenancy\ClinicModel;

/**
 * A phone number or email address that must not be sent messages.
 *
 * @property string $recipient
 */
class OptOut extends ClinicModel
{
    protected $table = 'msg_opt_outs';

    protected $fillable = ['recipient', 'created_by'];
}
