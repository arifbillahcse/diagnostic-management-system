<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use App\Core\Branches\Branch;
use App\Core\Documents\Barcode;
use App\Core\Documents\Letterhead;
use App\Core\Documents\PageLayout;
use App\Core\Documents\Signers;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Collection;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\WorkItem;

/**
 * Gathers everything a lab report shows. Only released results are ever
 * included; a test that is waiting, unverified, or being amended is left out.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly Letterhead $letterhead,
        private readonly PageLayout $layout,
        private readonly Signers $signers,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * @param  list<int>|null  $onlyWorkItemIds  limit the report to these tests
     * @return array<string, mixed>
     */
    public function forOrder(Order $order, ?array $onlyWorkItemIds = null): array
    {
        $items = WorkItem::query()
            ->where('order_id', $order->id)
            ->where('status', WorkStatus::Released->value)
            ->when($onlyWorkItemIds !== null, fn ($query) => $query->whereIn('id', $onlyWorkItemIds))
            ->with(['results', 'labTest', 'sample.sampleType', 'verifier'])
            ->orderBy('department')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new ReportException('No verified results are ready to print for this order.');
        }

        $waiting = WorkItem::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [WorkStatus::Released->value, WorkStatus::Cancelled->value])
            ->pluck('test_name')
            ->all();

        $signers = array_map(
            fn (array $s): array => ['name' => $s['name'], 'lines' => $s['lines'], 'signature' => $s['signature']],
            $this->signers->for($items->pluck('verifier')->filter()->unique('id')->pluck('id')->all()),
        );

        return [
            'clinic' => $this->letterhead->data(Branch::find($order->branch_id)),
            'timezone' => $this->clinics->require()->timezone,
            'order' => $order->loadMissing(['patient', 'referralDoctor', 'invoice']),
            'info' => $this->info($order, $items),
            'patient' => $order->patient,
            'departments' => $items->groupBy(fn (WorkItem $w): string => $w->department ?: 'Laboratory'),
            'items' => $items,
            'waiting' => $onlyWorkItemIds === null ? $waiting : [],
            'signers' => $signers,
            'amended' => $items->contains(fn (WorkItem $w): bool => $w->version > 1),
            'layout' => $this->layout->data(),
            'printed_at' => now($this->clinics->require()->timezone),
        ];
    }

    /**
     * What the top block of the report says besides the patient: bill and
     * lab numbers (with barcodes), specimen, and the dates.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return array<string, mixed>
     */
    private function info(Order $order, $items): array
    {
        $samples = $items->pluck('sample')->filter()->unique('id')->sortBy('collected_at')->values();
        $labNos = $samples->pluck('accession_no')->all();
        $billNo = $order->invoice?->invoice_no;
        $doctor = $order->referralDoctor;

        return [
            'bill_no' => $billNo,
            'lab_no' => implode(', ', $labNos),
            'bill_barcode' => $billNo ? Barcode::pngDataUri($billNo) : null,
            'lab_barcode' => $labNos !== [] ? Barcode::pngDataUri($labNos[0]) : null,
            'specimen' => $samples->map(fn ($s) => $s->sampleType?->name)->filter()->unique()->implode(', '),
            'received_at' => $samples->pluck('received_at')->filter()->sort()->first(),
            'collected_at' => $samples->pluck('collected_at')->filter()->sort()->first(),
            'delivery_at' => $order->delivery_at,
            'consultant' => $doctor ? trim(($doctor->code ? $doctor->code.', ' : '').$doctor->name.($doctor->degree ? ', '.$doctor->degree : '')) : null,
        ];
    }
}
