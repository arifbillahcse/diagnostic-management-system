<?php

declare(strict_types=1);

namespace Modules\People\Support;

use RuntimeException;

/** A staff or attendance rule was broken; the message is safe to show. */
final class PeopleException extends RuntimeException {}
