<?php

declare(strict_types=1);

namespace App\Core\Numbering;

use App\Core\Branches\Branch;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Builds business numbers (patient, invoice, receipt, ...) from the clinic's
 * formats and the counter table. Call inside the creating transaction.
 */
final class NumberGenerator
{
    /** Formats used until a clinic sets its own under the "numbering" settings group. */
    public const DEFAULT_FORMATS = [
        'patient' => 'P-{yy}-{000001}',
        'order' => 'ORD-{branch}-{yymm}-{00001}',
        'invoice' => 'INV-{branch}-{yymm}-{00001}',
        'receipt' => 'RCP-{branch}-{yymm}-{00001}',
        'refund' => 'RFD-{branch}-{yymm}-{00001}',
        'accession' => '{yymmdd}{0001}',
    ];

    public function __construct(
        private readonly CounterService $counters,
        private readonly Settings $settings,
        private readonly ClinicContext $clinics,
    ) {}

    public function format(string $type): NumberFormat
    {
        $pattern = $this->settings->get("numbering.{$type}", self::DEFAULT_FORMATS[$type] ?? null);

        if (! is_string($pattern)) {
            throw new InvalidArgumentException("No number format is defined for [{$type}].");
        }

        return new NumberFormat($pattern);
    }

    public function generate(string $type, ?Branch $branch = null, ?CarbonInterface $at = null): string
    {
        $format = $this->format($type);

        if ($format->usesBranch() && $branch === null) {
            throw new InvalidArgumentException("The [{$type}] number format needs a branch.");
        }

        // Periods follow the clinic's local calendar, not UTC.
        $date = ($at ?? now())->copy()->setTimezone($this->clinics->require()->timezone);
        $branchId = $format->usesBranch() ? $branch->id : 0;

        $sequence = $this->counters->next($type, $branchId, $format->period($date));

        return $format->render($sequence, $date, $branch?->code ?? '');
    }
}
