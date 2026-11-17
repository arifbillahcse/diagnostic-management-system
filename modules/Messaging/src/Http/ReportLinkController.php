<?php

declare(strict_types=1);

namespace Modules\Messaging\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\PdfRenderer;
use App\Core\Settings\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Models\Invoice;
use Modules\Messaging\Models\ReportLink;
use Modules\Messaging\Support\ReportLinks;
use Modules\Reports\Permissions as ReportPermissions;
use Modules\Reports\Support\ReportBuilder;
use Modules\Reports\Support\ReportException;

/**
 * The address in a "report ready" message. Nobody is signed in here: the
 * long random link is the only key, so the page says as little as possible
 * when something is wrong, and every download is recorded.
 */
final class ReportLinkController
{
    public function show(string $token, ReportLinks $links, ReportBuilder $builder, PdfRenderer $pdf, Settings $settings, AuditLogger $audit): Response
    {
        $link = $links->find($token);

        if ($link === null || ! $link->usable() || $link->order === null) {
            return $this->unavailable('This link does not work any more. Please contact the clinic for your report.', 404);
        }

        $order = $link->order;
        $invoice = Invoice::where('order_id', $order->id)->first();

        // The clinic's own rule on reports and unpaid bills applies here too.
        if ($settings->get(ReportPermissions::SETTING_BLOCK_WHEN_DUE, false) && $invoice !== null && $invoice->due_amount->isPositive()) {
            return $this->unavailable('Your report is ready. Please contact the clinic to collect it.', 403);
        }

        try {
            $data = $builder->forOrder($order);
        } catch (ReportException) {
            return $this->unavailable('Your report is not ready yet. Please try again later.', 404);
        }

        $bytes = $pdf->render('reports::lab-report', $data);

        DB::connection('clinic')->transaction(function () use ($link, $order, $audit): void {
            ReportLink::whereKey($link->id)->update(['opened_count' => DB::raw('opened_count + 1'), 'last_opened_at' => now()]);
            $audit->record('report.link_opened', $order, [], [], ['order_no' => $order->order_no]);
        });

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$order->order_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function unavailable(string $message, int $status): Response
    {
        return response()->view('messaging::link-unavailable', ['message' => $message], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
