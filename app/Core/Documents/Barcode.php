<?php

declare(strict_types=1);

namespace App\Core\Documents;

use Picqer\Barcode\Renderers\PngRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * A Code 128 barcode as a small PNG data URI, for documents that are turned
 * into a PDF (the PDF engine handles PNG images more reliably than inline SVG).
 */
final class Barcode
{
    public static function pngDataUri(string $value, int $height = 30): string
    {
        $barcode = (new TypeCode128)->getBarcode($value);
        // A whole number of pixels per bar keeps the bars sharp and scannable.
        $width = $barcode->getWidth() * 2;
        $png = (new PngRenderer)->render($barcode, $width, $height);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
