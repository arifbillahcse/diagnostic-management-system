<?php

declare(strict_types=1);

namespace App\Core\Money;

/**
 * An amount in English words, using the lakh and crore grouping used in
 * Bangladesh: 125000 is "One Lakh Twenty Five Thousand".
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** "One Thousand Three Hundred Taka Only", or "... Taka and Fifty Paisa Only". */
    public static function for(Money $amount): string
    {
        $minor = abs($amount->minor);
        $taka = intdiv($minor, 100);
        $paisa = $minor % 100;

        if ($taka === 0 && $paisa === 0) {
            return 'Zero Taka Only';
        }

        $words = [];

        if ($taka > 0) {
            $words[] = self::number($taka).' Taka';
        }

        if ($paisa > 0) {
            $words[] = ($taka > 0 ? 'and ' : '').self::number($paisa).' Paisa';
        }

        return implode(' ', $words).' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];

        foreach ([10_000_000 => 'Crore', 100_000 => 'Lakh', 1_000 => 'Thousand', 100 => 'Hundred'] as $size => $name) {
            if ($n >= $size) {
                $parts[] = self::number(intdiv($n, $size)).' '.$name;
                $n %= $size;
            }
        }

        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
