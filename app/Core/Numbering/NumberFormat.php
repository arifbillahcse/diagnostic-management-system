<?php

declare(strict_types=1);

namespace App\Core\Numbering;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A business number pattern such as "INV-{branch}-{yymm}-{00001}".
 *
 * Tokens: {branch} {yyyy} {yy} {mm} {dd} {yymm} {yymmdd}, and one sequence
 * token written as zeros ending in 1, whose length is the minimum width.
 */
final readonly class NumberFormat
{
    private const SEQUENCE = '/\{(0*1)\}/';

    public function __construct(public string $pattern)
    {
        if (preg_match_all(self::SEQUENCE, $pattern) !== 1) {
            throw new InvalidArgumentException("Number format [{$pattern}] needs exactly one sequence token such as {00001}.");
        }
    }

    public function usesBranch(): bool
    {
        return str_contains($this->pattern, '{branch}');
    }

    /**
     * The counter period for a date: the sequence restarts whenever this
     * value changes. It follows the most precise date token in the pattern.
     */
    public function period(CarbonInterface $date): string
    {
        return match (true) {
            $this->has('{dd}', '{yymmdd}') => $date->format('ymd'),
            $this->has('{mm}', '{yymm}') => $date->format('ym'),
            $this->has('{yy}', '{yyyy}') => $date->format('y'),
            default => '',
        };
    }

    public function render(int $sequence, CarbonInterface $date, string $branchCode = ''): string
    {
        $number = strtr($this->pattern, [
            '{branch}' => $branchCode,
            '{yyyy}' => $date->format('Y'),
            '{yy}' => $date->format('y'),
            '{mm}' => $date->format('m'),
            '{dd}' => $date->format('d'),
            '{yymm}' => $date->format('ym'),
            '{yymmdd}' => $date->format('ymd'),
        ]);

        return preg_replace_callback(
            self::SEQUENCE,
            fn (array $m): string => str_pad((string) $sequence, strlen($m[1]), '0', STR_PAD_LEFT),
            $number,
        );
    }

    private function has(string ...$tokens): bool
    {
        foreach ($tokens as $token) {
            if (str_contains($this->pattern, $token)) {
                return true;
            }
        }

        return false;
    }
}
