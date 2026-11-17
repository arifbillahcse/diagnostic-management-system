<?php

declare(strict_types=1);

namespace Modules\Analyzers\Support\Parsers;

use Modules\Analyzers\Support\AnalyzerException;

/**
 * Reads HL7 v2 result messages (ORU^R01). MLLP framing is removed first.
 *
 * Each OBR starts a specimen; its sample number may be in OBR-3 (filler
 * order number) or OBR-2 (placer), or in an SPM-2 segment, so all are
 * tried. A result's code is OBX-3's first part, with its second part
 * (often the short name, such as "WBC") as an alternative.
 */
final class Hl7Parser
{
    public function parse(string $raw): Reading
    {
        $raw = str_replace(["\x0b", "\x1c"], '', $raw);
        $segments = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw) ?: []), fn ($s) => $s !== ''));

        if ($segments === [] || ! str_starts_with($segments[0], 'MSH')) {
            throw new AnalyzerException('This is not an HL7 message: it does not start with an MSH segment.');
        }

        $field = $segments[0][3] ?? '|';
        $component = $segments[0][4] ?? '^';

        $specimens = [];
        $current = null;

        foreach ($segments as $segment) {
            $fields = explode($field, $segment);

            switch ($fields[0]) {
                case 'OBR':
                    if ($current !== null) {
                        $specimens[] = $current;
                    }
                    $current = ['codes' => $this->codes([$fields[3] ?? '', $fields[2] ?? ''], $component), 'results' => []];
                    break;

                case 'SPM':
                    if ($current !== null) {
                        $current['codes'] = array_values(array_unique([...$current['codes'], ...$this->codes([$fields[2] ?? ''], $component)]));
                    }
                    break;

                case 'OBX':
                    $type = strtoupper(trim($fields[2] ?? ''));
                    $value = trim($fields[5] ?? '');

                    if ($current === null || $value === '' || in_array($type, ['ED', 'RP'], true)) {
                        break; // images and links are not results
                    }

                    $id = array_map('trim', explode($component, $fields[3] ?? ''));
                    $code = $id[0] !== '' ? $id[0] : ($id[1] ?? '');

                    if ($code === '') {
                        break;
                    }

                    $current['results'][] = [
                        'code' => $code,
                        'alt' => isset($id[1]) && $id[1] !== '' && $id[1] !== $code ? $id[1] : null,
                        'value' => trim(explode($component, $value)[0]),
                        'unit' => $this->nullable(explode($component, $fields[6] ?? '')[0]),
                        'flag' => $this->nullable($fields[8] ?? ''),
                    ];
                    break;
            }
        }

        if ($current !== null) {
            $specimens[] = $current;
        }

        return new Reading($specimens);
    }

    /** @param list<string> $values @return list<string> */
    private function codes(array $values, string $component): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $v): string => trim(explode($component, $v)[0]), $values), fn ($c) => $c !== '')));
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 30);
    }
}
