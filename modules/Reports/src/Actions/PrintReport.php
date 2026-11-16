<?php

declare(strict_types=1);

namespace Modules\Reports\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\PdfRenderer;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Models\WorkItem;
use Modules\Reports\Permissions;
use Modules\Reports\Support\ReportBuilder;
use Modules\Reports\Support\ReportException;

/**
 * Produces the PDF report of an order's verified results and records that
 * it was printed.
 */
final class PrintReport
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly PdfRenderer $pdf,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>|null  $onlyWorkItemIds
     * @return string the PDF file's contents
     */
    public function handle(Order $order, ?array $onlyWorkItemIds = null): string
    {
        Gate::authorize(Permissions::REPORTS_PRINT, $order->branch_id);

        $invoice = Invoice::where('order_id', $order->id)->first();

        if ($this->settings->get(Permissions::SETTING_BLOCK_WHEN_DUE, false)
            && $invoice !== null
            && $invoice->due_amount->isPositive()
            && ! Gate::allows(Permissions::REPORTS_PRINT_WITH_DUE, $order->branch_id)) {
            throw new ReportException("This invoice has a due of {$invoice->due_amount->formatted()}. Collect the due before giving the report.");
        }

        $data = $this->builder->forOrder($order, $onlyWorkItemIds);
        $bytes = $this->pdf->render('reports::lab-report', $data);

        $this->audit->record('report.printed', $order, [], [], [
            'order_no' => $order->order_no,
            'tests' => $data['items']->map(fn (WorkItem $w): string => $w->test_name.' (v'.$w->version.')')->all(),
        ]);

        return $bytes;
    }
}
