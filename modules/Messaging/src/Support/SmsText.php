<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

/**
 * How many SMS parts a text takes, which is what the provider charges for.
 * Plain Latin text fits 160 characters in one part (153 each when split);
 * anything else, such as Bengali, fits 70 (67 each when split).
 */
final class SmsText
{
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_DOUBLE = '^{}\\[~]|€';

    public static function segments(string $text): int
    {
        $length = 0;
        $plain = true;

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::GSM, $char) !== false) {
                $length++;
            } elseif (mb_strpos(self::GSM_DOUBLE, $char) !== false) {
                $length += 2;
            } else {
                $plain = false;
                break;
            }
        }

        if (! $plain) {
            $length = mb_strlen($text);

            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
