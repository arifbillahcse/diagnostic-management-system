<?php

declare(strict_types=1);

namespace Modules\Laboratory\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Permissions;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * A printable sample label with a Code 128 barcode of the sample number.
 */
final class LabelController
{
    public function show(Sample $sample): View
    {
        Gate::authorize(Permissions::WORK_VIEW, $sample->branch_id);

        $barcode = (new TypeCode128)->getBarcode($sample->accession_no);
        $svg = (new SvgRenderer)->render($barcode, 300, 60);

        return view('laboratory::label', [
            'sample' => $sample->load(['patient', 'sampleType', 'workItems']),
            // Drop the XML declaration so the SVG can sit inside the page.
            'barcode' => preg_replace('/^<\?xml.*?\?>\s*(<!DOCTYPE.*?>\s*)?/s', '', $svg),
            'copies' => max(1, (int) $sample->sampleType->label_copies),
        ]);
    }
}
