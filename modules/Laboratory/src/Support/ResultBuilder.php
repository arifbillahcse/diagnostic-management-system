<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use App\Core\Patients\Patient;
use Carbon\CarbonInterface;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\ResultType;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\ReferenceRange;

/**
 * Turns what a technician typed into the stored result: the value, the
 * unit and reference range copied from the catalog, and the flag.
 *
 * The flag is always calculated here. Nothing else sets it.
 */
final class ResultBuilder
{
    public function __construct(private readonly RangeFinder $ranges) {}

    /**
     * @return array{value_numeric: ?string, value_text: string, unit: ?string, ref_low: ?string, ref_high: ?string, ref_text: ?string, flag: Flag}
     */
    public function build(Analyte $analyte, string $typed, Patient $patient, CarbonInterface $collectedOn): array
    {
        $typed = trim($typed);

        return match ($analyte->result_type) {
            ResultType::Numeric => $this->numeric($analyte, $typed, $patient, $collectedOn),
            ResultType::Option => $this->option($analyte, $typed),
            ResultType::Text, ResultType::LongText => $this->text($analyte, $typed),
        };
    }

    /** The range a numeric analyte will be judged against, for showing beside the input. */
    public function rangePreview(Analyte $analyte, Patient $patient, CarbonInterface $collectedOn): ?string
    {
        if ($analyte->result_type !== ResultType::Numeric) {
            return null;
        }

        $range = $this->ranges->find($analyte, $patient->sex->value, $patient->ageInDays($collectedOn), $collectedOn);

        return $range === null ? null : $this->rangeText($analyte, $range);
    }

    /** @return array<string, mixed> */
    private function numeric(Analyte $analyte, string $typed, Patient $patient, CarbonInterface $collectedOn): array
    {
        if (! Decimal::isValid($typed)) {
            throw new LabException("\"{$typed}\" is not a valid number for {$analyte->name}.");
        }

        $value = Decimal::micro($typed);
        $range = $this->ranges->find($analyte, $patient->sex->value, $patient->ageInDays($collectedOn), $collectedOn);

        return [
            'value_numeric' => Decimal::toStorage($value),
            'value_text' => Decimal::format($value, $analyte->decimals),
            'unit' => $analyte->unit,
            'ref_low' => $this->limit($range?->low),
            'ref_high' => $this->limit($range?->high),
            'ref_text' => $range === null ? null : $this->rangeText($analyte, $range),
            'flag' => $this->flag($value, $range),
        ];
    }

    /** @return array<string, mixed> */
    private function option(Analyte $analyte, string $typed): array
    {
        $options = $analyte->options ?? [];

        if (! in_array($typed, $options, true)) {
            throw new LabException("\"{$typed}\" is not one of the answers allowed for {$analyte->name}.");
        }

        $abnormal = $analyte->abnormal_options ?? [];
        $normal = array_values(array_diff($options, $abnormal));

        return [
            'value_numeric' => null,
            'value_text' => $typed,
            'unit' => $analyte->unit,
            'ref_low' => null,
            'ref_high' => null,
            'ref_text' => $abnormal === [] || $normal === [] ? null : implode(' / ', $normal),
            'flag' => $abnormal === [] ? Flag::None : (in_array($typed, $abnormal, true) ? Flag::Abnormal : Flag::Normal),
        ];
    }

    /** @return array<string, mixed> */
    private function text(Analyte $analyte, string $typed): array
    {
        $limit = $analyte->result_type === ResultType::LongText ? 5000 : 255;

        if (mb_strlen($typed) > $limit) {
            throw new LabException("{$analyte->name} is longer than {$limit} characters.");
        }

        return [
            'value_numeric' => null,
            'value_text' => $typed,
            'unit' => $analyte->unit,
            'ref_low' => null,
            'ref_high' => null,
            'ref_text' => null,
            'flag' => Flag::None,
        ];
    }

    /**
     * A value equal to a limit is inside the range. Critical limits are
     * checked first, so a critical value is never reported as merely low
     * or high.
     */
    private function flag(int $value, ?ReferenceRange $range): Flag
    {
        if ($range === null) {
            return Flag::None;
        }

        $low = Decimal::micro($range->low);
        $high = Decimal::micro($range->high);
        $criticalLow = Decimal::micro($range->critical_low);
        $criticalHigh = Decimal::micro($range->critical_high);

        return match (true) {
            $criticalLow !== null && $value < $criticalLow => Flag::CriticalLow,
            $criticalHigh !== null && $value > $criticalHigh => Flag::CriticalHigh,
            $low !== null && $value < $low => Flag::Low,
            $high !== null && $value > $high => Flag::High,
            $low === null && $high === null && $criticalLow === null && $criticalHigh === null => Flag::None,
            default => Flag::Normal,
        };
    }

    private function rangeText(Analyte $analyte, ReferenceRange $range): ?string
    {
        if (filled($range->text_range)) {
            return $range->text_range;
        }

        $low = Decimal::micro($range->low);
        $high = Decimal::micro($range->high);
        $show = fn (int $micro): string => Decimal::format($micro, $analyte->decimals);

        return match (true) {
            $low !== null && $high !== null => $show($low).' - '.$show($high),
            $high !== null => 'Up to '.$show($high),
            $low !== null => $show($low).' or more',
            default => null,
        };
    }

    private function limit(string|int|float|null $value): ?string
    {
        $micro = Decimal::micro($value);

        return $micro === null ? null : Decimal::toStorage($micro);
    }
}
