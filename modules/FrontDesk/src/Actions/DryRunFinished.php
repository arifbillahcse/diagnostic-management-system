<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Actions;

use RuntimeException;

/** Ends a trial import, undoing everything it did. */
final class DryRunFinished extends RuntimeException {}
