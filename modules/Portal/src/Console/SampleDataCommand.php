<?php

declare(strict_types=1);

namespace Modules\Portal\Console;

use App\Core\Console\SampleDataCommand as Base;
use App\Core\Tenancy\Clinic;
use Modules\FrontDesk\Models\Invoice;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;
use RuntimeException;

/**
 * The patient portal has no data of its own: it shows the reports that exist.
 * This writes nothing; it finds a bill whose reports are all released and
 * prints the bill number and mobile number that open it on the portal.
 */
final class SampleDataCommand extends Base
{
    protected $signature = 'portal:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Show a bill number and mobile number that open a released report on the patient portal (writes nothing)';

    protected function moduleKey(): string
    {
        return 'portal';
    }

    protected function load(Clinic $clinic): array
    {
        $open = WorkItem::whereNot('status', WorkStatus::Released->value)->whereNot('status', WorkStatus::Cancelled->value)->select('order_id');
        $invoice = Invoice::whereIn('order_id', WorkItem::where('status', WorkStatus::Released->value)->select('order_id'))
            ->whereNotIn('order_id', $open)
            ->with('order.patient')
            ->orderBy('id')->first();

        if ($invoice === null || $invoice->order?->patient?->phone === null) {
            throw new RuntimeException('No bill has all its reports released yet. Run laboratory:sample-data first.');
        }

        $domain = $clinic->domains()->orderByDesc('is_primary')->orderBy('id')->value('domain');

        return [
            'Patient portal: open '.($domain ? "https://{$domain}/reports" : 'the /reports page on the clinic address'),
            "  bill number: {$invoice->invoice_no}",
            "  mobile number: {$invoice->order->patient->phone}",
        ];
    }
}
