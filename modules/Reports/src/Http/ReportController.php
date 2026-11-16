<?php

declare(strict_types=1);

namespace Modules\Reports\Http;

use Illuminate\Http\Response;
use Modules\FrontDesk\Models\Order;
use Modules\Reports\Actions\PrintReport;
use Modules\Reports\Support\ReportException;

final class ReportController
{
    public function pdf(Order $order, PrintReport $print): Response
    {
        try {
            $bytes = $print->handle($order);
        } catch (ReportException $e) {
            return response()->view('reports::unavailable', ['message' => $e->getMessage(), 'order' => $order], 409);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$order->order_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
