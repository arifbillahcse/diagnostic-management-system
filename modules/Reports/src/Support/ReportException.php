<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use DomainException;

/** A report that cannot be produced. The message is safe to show to staff. */
class ReportException extends DomainException {}
