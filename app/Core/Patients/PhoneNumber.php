<?php

declare(strict_types=1);

namespace App\Core\Patients;

/**
 * Reduces a phone number to digits with the Bangladesh country code, so the
 * same number typed in different ways matches: "01711-000000",
 * "+880 1711 000000", and "8801711000000" all become "8801711000000".
 */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            $digits = '880'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '880'.$digits;
        }

        return substr($digits, 0, 20);
    }

    /**
     * The start of a stored number for a partly typed one, so "01822" finds
     * 8801822334455. Numbers are stored as 8801XXXXXXXXX.
     */
    public static function prefix(?string $typed): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $typed);

        return match (true) {
            $digits === '' || $digits === null => null,
            str_starts_with($digits, '00880') => substr($digits, 2),
            str_starts_with($digits, '880') => $digits,
            str_starts_with($digits, '01') => '880'.substr($digits, 1),
            str_starts_with($digits, '1') => '880'.$digits,
            default => $digits,
        };
    }
}
