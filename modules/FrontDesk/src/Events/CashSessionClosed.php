<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Events;

use Modules\FrontDesk\Models\CashSession;

/** Raised after the change has been committed. */
final class CashSessionClosed
{
    public function __construct(public readonly CashSession $session) {}
}
