<?php

declare(strict_types=1);

namespace Modules\Analyzers\Support\Parsers;

use Modules\Analyzers\Support\AnalyzerException;

/**
 * Reads ASTM E1394 records (H, P, O, R, L). Low-level framing (STX, frame
 * number, ETX/ETB with checksum, ENQ/ACK/EOT) is removed first, so both a
 * cleaned message and the line as sent by the machine are accepted.
 *
 * The specimen number is taken from the O record (field 3, then field 4);
 * a result's test code is the last filled part of the R record's field 3
 * (for example "^^^HGB" gives "HGB").
 */
final class AstmParser
{
    public function parse(string $raw): Reading
    {
        $records = $this->records($raw);

        if ($records === [] || ! str_starts_with(ltrim($records[0]), 'H')) {
            throw new AnalyzerException('This is not an ASTM message: it does not start with a header (H) record.');
        }

        $header = $records[0];
        $field = $header[1] ?? '|';
        $component = $header[3] ?? '^';

        $specimens = [];
        $current = null;

        foreach ($records as $record) {
            $fields = explode($field, $record);
            $type = strtoupper(substr($fields[0], -1));

            if ($type === 'O') {
                if ($current !== null) {
                    $specimens[] = $current;
                }
                $codes = array_values(array_unique(array_filter([
                    $this->first($fields[2] ?? '', $component),
                    $this->first($fields[3] ?? '', $component),
                ], fn (string $c): bool => $c !== '')));
                $current = ['codes' => $codes, 'results' => []];
            } elseif ($type === 'R' && $current !== null) {
                $parts = array_values(array_filter(array_map('trim', explode($component, $fields[2] ?? '')), fn ($p) => $p !== ''));
                $value = trim($fields[3] ?? '');

                if ($parts === [] || $value === '') {
                    continue;
                }

                $current['results'][] = [
                    'code' => end($parts),
                    'alt' => count($parts) > 1 ? $parts[0] : null,
                    'value' => $this->first($value, $component),
                    'unit' => $this->nullable($fields[4] ?? ''),
                    'flag' => $this->nullable($fields[6] ?? ''),
                ];
            }
        }

        if ($current !== null) {
            $specimens[] = $current;
        }

        return new Reading($specimens);
    }

    /** @return list<string> */
    private function records(string $raw): array
    {
        // Control characters of the low-level protocol.
        $raw = str_replace(["\x05", "\x04", "\x06", "\x15"], '', $raw);
        // "<STX>n" frame starts, and "<ETX|ETB>cc" frame ends with their checksum.
        $raw = preg_replace('/\x02[0-7]/', '', $raw) ?? $raw;
        $raw = preg_replace('/[\x03\x17][0-9A-Fa-f]{2}/', "\r", $raw) ?? $raw;

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw) ?: []), fn (string $l): bool => $l !== '' && preg_match('/^\d?[A-Za-z]\W/', $l) === 1));
    }

    private function first(string $value, string $component): string
    {
        return trim(explode($component, $value)[0]);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 30);
    }
}
