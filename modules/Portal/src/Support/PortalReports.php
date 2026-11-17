<?php

declare(strict_types=1);

namespace Modules\Portal\Support;

use App\Core\Documents\PdfRenderer;
use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Actions\PrintImagingReport;
use Modules\Imaging\Models\Study;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use Modules\Reports\Permissions as ReportPermissions;
use Modules\Reports\Support\ReportBuilder;
use Modules\Reports\Support\ReportException;

/**
 * What a patient can see and download for one order: lab results that are
 * released, imaging reports that are signed (when imaging is installed and
 * on), and both in one file.
 */
final class PortalReports
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ReportBuilder $lab,
        private readonly PdfRenderer $pdf,
    ) {}

    private function imagingOn(): bool
    {
        return class_exists(Study::class) && app(ModuleState::class)->isEnabled('imaging');
    }

    /** The clinic does not hand over reports while the bill has a due. */
    public function heldForDue(Order $order): bool
    {
        if (! $this->settings->get(ReportPermissions::SETTING_BLOCK_WHEN_DUE, false)) {
            return false;
        }

        $invoice = Invoice::where('order_id', $order->id)->first();

        return $invoice !== null && $invoice->due_amount->isPositive();
    }

    /** @return array{lab_total: int, lab_ready: int, imaging: list<array{name: string, ready: bool}>, downloads: array<string, string>} */
    public function summary(Order $order): array
    {
        $work = WorkItem::where('order_id', $order->id)->where('status', '!=', WorkStatus::Cancelled->value)->get(['status']);
        $labReady = $work->filter(fn (WorkItem $w): bool => $w->status === WorkStatus::Released)->count();

        $imaging = $this->imagingOn()
            ? Study::where('order_id', $order->id)->where('status', '!=', 'cancelled')->orderBy('id')->get()
                ->map(fn (Study $s): array => ['name' => $s->study_name, 'ready' => $s->status === 'final'])->all()
            : [];
        $imagingReady = count(array_filter($imaging, fn (array $s): bool => $s['ready']));

        $downloads = [];
        if ($labReady > 0) {
            $downloads['lab'] = 'Lab report';
        }
        if ($imagingReady > 0) {
            $downloads['imaging'] = $imagingReady > 1 ? 'Imaging reports' : 'Imaging report';
        }
        if ($labReady > 0 && $imagingReady > 0) {
            $downloads['all'] = 'All reports in one file';
        }

        return ['lab_total' => $work->count(), 'lab_ready' => $labReady, 'imaging' => $imaging, 'downloads' => $downloads];
    }

    /** The PDF, or null when that kind is not ready. */
    public function pdf(Order $order, string $kind): ?string
    {
        if (! array_key_exists($kind, $this->summary($order)['downloads'])) {
            return null;
        }

        if ($kind === 'lab') {
            try {
                return $this->pdf->render('reports::lab-report', $this->lab->forOrder($order));
            } catch (ReportException) {
                return null;
            }
        }

        return app(PrintImagingReport::class)->forPatient($order, withLab: $kind === 'all');
    }
}
