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
    /**
     * Formats used until a clinic sets its own under the "numbering" settings
     * group. Short, without the branch code: a clinic with several branches
     * adds {branch} on the settings screen. Clinics made before these became
     * the defaults keep the formats they had (see the clinic migration
     * keep_number_formats_of_existing_clinics).
     */
    public const DEFAULT_FORMATS = [
        'patient' => 'P-{yy}-{000001}',
        'order' => 'ORD-{yymm}-{00001}',
        'invoice' => '{yymm}-{00001}',
        'receipt' => 'MR-{yymm}-{00001}',
        'refund' => 'RF-{yymm}-{00001}',
        'accession' => '{yymmdd}{0001}',
    ];

    /** What each core number type is called on the settings screen. */
    public const LABELS = [
        'patient' => 'Patient ID',
        'order' => 'Order number',
        'invoice' => 'Bill / invoice number',
        'receipt' => 'Payment receipt number',
        'refund' => 'Refund number',
        'accession' => 'Sample (lab) number',
    ];

    /** @var array<string, string> formats added by modules for their own number types */
    private static array $moduleFormats = [];

    /** @var array<string, array{label: string, module: ?string}> */
    private static array $moduleTypes = [];

    /** Lets a module give its own number type a default format, a name for the settings screen, and the module it belongs to. */
    public static function registerDefault(string $type, string $pattern, ?string $label = null, ?string $module = null): void
    {
        self::$moduleFormats[$type] = $pattern;
        self::$moduleTypes[$type] = ['label' => $label ?? ucfirst($type).' number', 'module' => $module];
    }

    /**
     * Every number type with its name, built-in format, and the module it
     * belongs to (null for the core).
     *
     * @return array<string, array{label: string, default: string, module: ?string}>
     */
    public static function types(): array
    {
        $types = [];

        foreach (self::DEFAULT_FORMATS as $type => $default) {
            $types[$type] = ['label' => self::LABELS[$type] ?? ucfirst($type), 'default' => $default, 'module' => null];
        }

        foreach (self::$moduleFormats as $type => $default) {
            $types[$type] = ['label' => self::$moduleTypes[$type]['label'], 'default' => $default, 'module' => self::$moduleTypes[$type]['module']];
        }

        return $types;
    }

    public function __construct(
        private readonly CounterService $counters,
        private readonly Settings $settings,
        private readonly ClinicContext $clinics,
    ) {}

    public function format(string $type): NumberFormat
    {
        $pattern = $this->settings->get("numbering.{$type}", self::DEFAULT_FORMATS[$type] ?? self::$moduleFormats[$type] ?? null);

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
