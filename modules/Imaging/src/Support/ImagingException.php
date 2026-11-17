<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

use RuntimeException;

/** An imaging rule was broken; the message is safe to show to staff. */
final class ImagingException extends RuntimeException {}
