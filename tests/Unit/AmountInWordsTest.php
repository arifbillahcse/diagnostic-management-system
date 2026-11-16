<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money\AmountInWords;
use App\Core\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInWordsTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function amounts(): array
    {
        return [
            'zero' => ['0', 'Zero Taka Only'],
            'one' => ['1', 'One Taka Only'],
            'teen' => ['13', 'Thirteen Taka Only'],
            'tens' => ['45', 'Forty Five Taka Only'],
            'round tens' => ['90', 'Ninety Taka Only'],
            'hundreds' => ['205', 'Two Hundred Five Taka Only'],
            'from the invoices' => ['1300', 'One Thousand Three Hundred Taka Only'],
            'two thousand two hundred' => ['2200', 'Two Thousand Two Hundred Taka Only'],
            'lakh' => ['125000', 'One Lakh Twenty Five Thousand Taka Only'],
            'lakh with nothing below' => ['100000', 'One Lakh Taka Only'],
            'crore' => ['12345678', 'One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Taka Only'],
            'paisa' => ['1300.50', 'One Thousand Three Hundred Taka and Fifty Paisa Only'],
            'paisa only' => ['0.05', 'Five Paisa Only'],
            'negative is read as its size' => ['-250', 'Two Hundred Fifty Taka Only'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_an_amount_is_written_in_words(string $amount, string $words): void
    {
        $this->assertSame($words, AmountInWords::for(Money::fromDecimal($amount)));
    }
}
