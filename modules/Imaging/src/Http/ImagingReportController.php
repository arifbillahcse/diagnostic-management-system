<?php

declare(strict_types=1);

namespace Modules\Imaging\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Actions\PrintImagingReport;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Support\ImagingException;

final class ImagingReportController
{
    /** All signed imaging reports of the order, or one with ?study=<public id>. */
    public function pdf(Request $request, Order $order, PrintImagingReport $print): Response
    {
        $only = null;

        if (filled($request->query('study'))) {
            $study = Study::where('order_id', $order->id)->where('public_id', (string) $request->query('study'))->firstOrFail();
            $only = [$study->id];
        }

        try {
            $bytes = $print->handle($order, $only);
        } catch (ImagingException $e) {
            return response($e->getMessage(), 409, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="imaging-'.$order->order_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
