<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use DomainException;

/**
 * A laboratory operation that was refused. Messages are written for clinic
 * staff and are safe to show on screen.
 */
class LabException extends DomainException {}
