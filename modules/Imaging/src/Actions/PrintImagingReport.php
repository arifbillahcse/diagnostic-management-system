<?php

declare(strict_types=1);

namespace Modules\Imaging\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\PdfRenderer;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ReportData;

/** Builds the PDF of an order's signed imaging reports and records that it was printed. */
final class PrintImagingReport
{
    public function __construct(
        private readonly ReportData $data,
        private readonly PdfRenderer $pdf,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<int>|null $onlyStudyIds */
    public function handle(Order $order, ?array $onlyStudyIds = null): string
    {
        Gate::authorize(Permissions::REPORTS_PRINT, $order->branch_id);

        $data = $this->data->forOrder($order, $onlyStudyIds);
        $bytes = $this->pdf->render('imaging::pdf', $data);

        $this->audit->record('imaging.printed', $order, [], [], [
            'order_no' => $order->order_no,
            'studies' => array_map(fn (array $p): string => $p['study']->study_name.' (v'.$p['version']->version.')', $data['pages']),
        ]);

        return $bytes;
    }
}
