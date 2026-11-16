<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use DomainException;

/** A finance operation that was refused. The message is safe to show to staff. */
class FinanceException extends DomainException {}
