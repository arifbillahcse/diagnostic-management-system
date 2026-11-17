<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests;

use App\Core\Money\Money;
use InvalidArgumentException;
use Modules\Inventory\Support\Quantity;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function test_quantities_are_exact_to_three_decimals(): void
    {
        $this->assertSame(2500, Quantity::fromDecimal('2.5')->milli);
        $this->assertSame('2.500', Quantity::fromDecimal('2.5')->toDecimal());
        $this->assertSame('2.5', Quantity::fromDecimal('2.500')->formatted());
        $this->assertSame('100', Quantity::fromDecimal('100')->formatted());
        $this->assertSame('0.125', Quantity::fromDecimal('0.125')->formatted());
        $this->assertSame('-1.5', Quantity::fromDecimal('-1.5')->formatted());
        $this->assertSame('0.3', Quantity::fromDecimal('0.1')->plus(Quantity::fromDecimal('0.2'))->formatted(), 'no float error');
    }

    public function test_bad_input_is_refused(): void
    {
        foreach (['', 'abc', '1.2345', '1,5', '1e3', '12.'] as $bad) {
            try {
                Quantity::fromDecimal($bad);
                $this->fail("[{$bad}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cost_and_average_share_are_rounded_to_the_poisha(): void
    {
        $this->assertSame('350.00', Quantity::fromDecimal('100')->costAt(Money::fromDecimal('3.50'))->toDecimal());
        $this->assertSame('8.75', Quantity::fromDecimal('2.5')->costAt(Money::fromDecimal('3.50'))->toDecimal());
        $this->assertSame('0.42', Quantity::fromDecimal('0.125')->costAt(Money::fromDecimal('3.33'))->toDecimal());

        // A third of 100.00 is 33.33; two thirds is 66.67.
        $this->assertSame('33.33', Quantity::fromDecimal('1')->shareOf(Money::fromDecimal('100'), Quantity::fromDecimal('3'))->toDecimal());
        $this->assertSame('66.67', Quantity::fromDecimal('2')->shareOf(Money::fromDecimal('100'), Quantity::fromDecimal('3'))->toDecimal());
        $this->assertSame('0.00', Quantity::fromDecimal('1')->shareOf(Money::fromDecimal('100'), Quantity::zero()->plus(Quantity::fromMilli(0)))->toDecimal());
    }
}
