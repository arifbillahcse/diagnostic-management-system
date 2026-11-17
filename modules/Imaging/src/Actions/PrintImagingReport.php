<?php

declare(strict_types=1);

namespace Modules\Imaging\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\PdfRenderer;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ImagingException;
use Modules\Imaging\Support\LabReports;
use Modules\Imaging\Support\ReportData;
use Modules\Reports\Permissions as ReportPermissions;

/**
 * Builds the PDF of an order's signed imaging reports, alone or after its
 * lab results in one file, and records that it was printed.
 */
final class PrintImagingReport
{
    public function __construct(
        private readonly ReportData $data,
        private readonly LabReports $lab,
        private readonly PdfRenderer $pdf,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<int>|null $onlyStudyIds */
    public function handle(Order $order, ?array $onlyStudyIds = null, bool $withLab = false): string
    {
        Gate::authorize(Permissions::REPORTS_PRINT, $order->branch_id);

        if ($withLab && ! $this->lab->mayPrintLab($order)) {
            Gate::authorize(ReportPermissions::REPORTS_PRINT, $order->branch_id);
        }

        if (($blocked = $this->lab->dueBlocks($order)) !== null) {
            throw new ImagingException($blocked);
        }

        return $this->render($order, $onlyStudyIds, $withLab, 'imaging.printed');
    }

    /**
     * For the patient's own download link: everything ready on the order,
     * lab and imaging together. No signed-in user; the link is the key, and
     * the link's own controller checks the clinic's due rule and records the
     * download.
     */
    public function forPatient(Order $order, bool $withLab = true): string
    {
        return $this->render($order, null, $withLab, null);
    }

    private function render(Order $order, ?array $onlyStudyIds, bool $withLab, ?string $auditAction): string
    {
        $data = $this->data->forOrder($order, $onlyStudyIds);

        if ($withLab && ($lab = $this->lab->dataFor($order)) !== null) {
            $data['lab'] = $lab;
        }

        $bytes = $this->pdf->render('imaging::pdf', $data);

        if ($auditAction !== null) {
            $this->audit->record($auditAction, $order, [], [], [
                'order_no' => $order->order_no,
                'with_lab' => isset($data['lab']),
                'studies' => array_map(fn (array $p): string => $p['study']->study_name.' (v'.$p['version']->version.')', $data['pages']),
            ]);
        }

        return $bytes;
    }
}
