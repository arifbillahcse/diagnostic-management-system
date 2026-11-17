<?php

declare(strict_types=1);

namespace Modules\Imaging\Events;

use Modules\Imaging\Models\Study;

/** An imaging report was signed: its first version, or an amendment. */
final class ReportSigned
{
    public function __construct(public readonly Study $study) {}
}
