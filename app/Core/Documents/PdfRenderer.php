<?php

declare(strict_types=1);

namespace App\Core\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Turns a Blade view into a PDF.
 *
 * Remote files and embedded PHP are switched off: a document can only
 * contain what the view itself puts in it. Images are embedded as data URIs.
 */
final class PdfRenderer
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $work = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($work);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache($work);
        $options->setTempDir($work);
        $options->setChroot([$work]);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $pdf->setPaper($paper, $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }
}
