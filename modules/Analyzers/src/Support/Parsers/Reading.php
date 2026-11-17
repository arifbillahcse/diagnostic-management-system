<?php

declare(strict_types=1);

namespace Modules\Analyzers\Support\Parsers;

/**
 * What a parser found in a message: for each specimen, the sample numbers it
 * may be known by (in order of likelihood) and its results.
 */
final class Reading
{
    /** @param list<array{codes: list<string>, results: list<array{code: string, alt: ?string, value: string, unit: ?string, flag: ?string}>}> $specimens */
    public function __construct(public readonly array $specimens) {}
}
