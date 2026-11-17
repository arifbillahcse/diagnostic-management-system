<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use RuntimeException;

/** Something the clinic's plan does not allow; the message is safe to show. */
final class PlanLimitException extends RuntimeException {}
