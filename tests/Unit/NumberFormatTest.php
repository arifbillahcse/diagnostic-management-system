<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Numbering\NumberFormat;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NumberFormatTest extends TestCase
{
    public function test_it_renders_tokens_and_pads_the_sequence(): void
    {
        $date = CarbonImmutable::parse('2026-11-05');

        $this->assertSame('INV-MAIN-2611-00042', (new NumberFormat('INV-{branch}-{yymm}-{00001}'))->render(42, $date, 'MAIN'));
        $this->assertSame('P-26-000007', (new NumberFormat('P-{yy}-{000001}'))->render(7, $date));
        $this->assertSame('2611050012', (new NumberFormat('{yymmdd}{0001}'))->render(12, $date));
        $this->assertSame('2026/11/05-3', (new NumberFormat('{yyyy}/{mm}/{dd}-{1}'))->render(3, $date));
    }

    public function test_a_sequence_longer_than_its_padding_is_not_cut(): void
    {
        $this->assertSame('X-123456', (new NumberFormat('X-{001}'))->render(123456, CarbonImmutable::now()));
    }

    public function test_the_period_follows_the_most_precise_date_token(): void
    {
        $date = CarbonImmutable::parse('2026-11-05');

        $this->assertSame('261105', (new NumberFormat('{yymmdd}{0001}'))->period($date));
        $this->assertSame('2611', (new NumberFormat('INV-{yymm}-{00001}'))->period($date));
        $this->assertSame('26', (new NumberFormat('P-{yy}-{000001}'))->period($date));
        $this->assertSame('', (new NumberFormat('P-{000001}'))->period($date));
    }

    public function test_it_knows_whether_it_needs_a_branch(): void
    {
        $this->assertTrue((new NumberFormat('INV-{branch}-{00001}'))->usesBranch());
        $this->assertFalse((new NumberFormat('P-{000001}'))->usesBranch());
    }

    public function test_exactly_one_sequence_token_is_required(): void
    {
        foreach (['INV-{yymm}', 'INV-{001}-{001}', 'INV-{000}'] as $pattern) {
            try {
                new NumberFormat($pattern);
                $this->fail("Pattern [{$pattern}] should have been refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
