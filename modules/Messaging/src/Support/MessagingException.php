<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use RuntimeException;

/** A messaging rule was broken; the message is safe to show to staff. */
final class MessagingException extends RuntimeException {}
