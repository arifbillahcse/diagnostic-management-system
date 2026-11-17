<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use Modules\Reports\Permissions as ReportPermissions;
use Modules\Reports\Support\ReportBuilder;
use Modules\Reports\Support\ReportException;

/**
 * What imaging uses from the lab reports module when that module is
 * installed and on (it is only suggested, never required): the lab part of
 * a combined report, and the clinic's rule on printing while a due is unpaid.
 */
final class LabReports
{
    public function available(): bool
    {
        return class_exists(ReportBuilder::class) && app(ModuleState::class)->isEnabled('reports');
    }

    /** The lab report data of the order, or null when no lab result is released. @return array<string, mixed>|null */
    public function dataFor(Order $order): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            return app(ReportBuilder::class)->forOrder($order);
        } catch (ReportException) {
            return null;
        }
    }

    /** Cheap check: does the order have at least one released lab result? */
    public function hasReleased(Order $order): bool
    {
        return $this->available()
            && WorkItem::where('order_id', $order->id)->where('status', WorkStatus::Released->value)->exists();
    }

    public function mayPrintLab(Order $order): bool
    {
        return $this->available() && Gate::allows(ReportPermissions::REPORTS_PRINT, $order->branch_id);
    }

    /**
     * The clinic's "do not print while there is a due" rule, set under Lab
     * report printing, applies to imaging reports too.
     */
    public function dueBlocks(Order $order, bool $forPatientLink = false): ?string
    {
        if (! $this->available() || ! app(Settings::class)->get(ReportPermissions::SETTING_BLOCK_WHEN_DUE, false)) {
            return null;
        }

        $invoice = Invoice::where('order_id', $order->id)->first();

        if ($invoice === null || ! $invoice->due_amount->isPositive()) {
            return null;
        }

        if (! $forPatientLink && Gate::allows(ReportPermissions::REPORTS_PRINT_WITH_DUE, $order->branch_id)) {
            return null;
        }

        return "This invoice has a due of {$invoice->due_amount->formatted()}. Collect the due before giving the report.";
    }
}
