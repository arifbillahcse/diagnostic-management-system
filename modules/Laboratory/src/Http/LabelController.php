<?php

declare(strict_types=1);

namespace Modules\Laboratory\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Permissions;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Printable sample labels with a Code 128 barcode of the sample number: one
 * sample, or every sample of an order in one go.
 */
final class LabelController
{
    public function show(Request $request, Sample $sample): View
    {
        // The collecting branch prints labels; the laboratory may print them again.
        if (! Gate::allows(Permissions::WORK_VIEW, $sample->branch_id)) {
            Gate::authorize(Permissions::WORK_VIEW, $sample->labBranchId());
        }

        return $this->labels(new Collection([$sample]), $request->boolean('autoprint'));
    }

    /** Every sample still to be taken or in the laboratory, for an order just billed. */
    public function forOrder(Request $request, Order $order): View
    {
        Gate::authorize(Permissions::WORK_VIEW, $order->branch_id);

        return $this->labels(self::samplesOf($order), $request->boolean('autoprint'));
    }

    /** @return Collection<int, Sample> */
    public static function samplesOf(Order $order): Collection
    {
        return Sample::where('order_id', $order->id)
            ->whereNotIn('status', [SampleStatus::Cancelled->value, SampleStatus::Rejected->value])
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, Sample> $samples */
    private function labels(Collection $samples, bool $autoprint): View
    {
        $samples->load(['patient', 'sampleType', 'workItems']);

        $labels = $samples->map(function (Sample $sample): array {
            $svg = (new SvgRenderer)->render((new TypeCode128)->getBarcode($sample->accession_no), 300, 60);

            return [
                'sample' => $sample,
                // Drop the XML declaration so the SVG can sit inside the page.
                'barcode' => preg_replace('/^<\?xml.*?\?>\s*(<!DOCTYPE.*?>\s*)?/s', '', $svg),
                'copies' => max(1, (int) $sample->sampleType->label_copies),
            ];
        });

        return view('laboratory::label', ['labels' => $labels, 'autoprint' => $autoprint]);
    }
}
