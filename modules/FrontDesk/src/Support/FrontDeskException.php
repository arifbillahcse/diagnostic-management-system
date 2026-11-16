<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use DomainException;

/**
 * A front desk operation that was refused. Messages are written for clinic
 * staff and are safe to show on screen.
 */
class FrontDeskException extends DomainException {}
