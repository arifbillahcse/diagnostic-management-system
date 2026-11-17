<?php

declare(strict_types=1);

namespace Modules\Analyzers\Support;

use RuntimeException;

/** A machine integration rule was broken; the message is safe to show to staff. */
final class AnalyzerException extends RuntimeException {}
