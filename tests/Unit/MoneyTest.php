<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('decimals')]
    public function test_it_parses_and_prints_decimals_exactly(string|int $input, int $minor, string $printed): void
    {
        $money = Money::fromDecimal($input);

        $this->assertSame($minor, $money->minor);
        $this->assertSame($printed, $money->toDecimal());
    }

    public static function decimals(): array
    {
        return [
            ['0', 0, '0.00'],
            ['1250', 125000, '1250.00'],
            ['1250.5', 125050, '1250.50'],
            ['1250.05', 125005, '1250.05'],
            ['-10.25', -1025, '-10.25'],
            ['-0.05', -5, '-0.05'],
            [300, 30000, '300.00'],
            ['9999999999.99', 999999999999, '9999999999.99'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_it_refuses_malformed_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal($input);
    }

    public static function invalid(): array
    {
        return [[''], ['abc'], ['1.234'], ['1,250.00'], ['1e3'], ['.5'], ['5.'], ['--5'], ['1 250']];
    }

    public function test_arithmetic_has_no_floating_point_drift(): void
    {
        // 0.1 + 0.2 is the classic float failure.
        $this->assertSame('0.30', Money::fromDecimal('0.10')->plus(Money::fromDecimal('0.20'))->toDecimal());

        $total = Money::zero();
        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::fromDecimal('0.01'));
        }
        $this->assertSame('10.00', $total->toDecimal());

        $this->assertSame('749.70', Money::fromDecimal('249.90')->times(3)->toDecimal());
        $this->assertSame('-50.00', Money::fromDecimal('100')->minus(Money::fromDecimal('150'))->toDecimal());
        $this->assertSame('6.00', Money::sum(Money::fromDecimal('1'), Money::fromDecimal('2'), Money::fromDecimal('3'))->toDecimal());
    }

    #[DataProvider('percentages')]
    public function test_percentages_round_half_up(string $amount, string $percent, string $expected): void
    {
        $this->assertSame($expected, Money::fromDecimal($amount)->percent($percent)->toDecimal());
    }

    public static function percentages(): array
    {
        return [
            ['1000', '10', '100.00'],
            ['999.99', '10', '100.00'],   // 99.999 rounds up
            ['0.05', '50', '0.03'],       // 0.025 rounds half up
            ['0.04', '10', '0.00'],       // 0.004 rounds down
            ['333.33', '7.5', '25.00'],   // 24.99975
            ['1250', '12.25', '153.13'],  // 153.125 rounds half up
            ['-0.05', '50', '-0.03'],     // half rounds away from zero
            ['500', '0', '0.00'],
            ['500', '100', '500.00'],
        ];
    }

    public function test_comparisons(): void
    {
        $ten = Money::fromDecimal('10');
        $twenty = Money::fromDecimal('20');

        $this->assertTrue($ten->lessThan($twenty));
        $this->assertTrue($twenty->greaterThan($ten));
        $this->assertTrue($ten->equals(Money::fromMinor(1000)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue($ten->negate()->isNegative());
        $this->assertTrue($ten->isPositive());
        $this->assertSame('"10.00"', json_encode($ten));
    }
}
