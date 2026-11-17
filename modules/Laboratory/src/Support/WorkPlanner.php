<?php

declare(strict_types=1);

namespace Modules\Laboratory\Support;

use App\Core\Numbering\NumberGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\SampleEvent;
use Modules\Laboratory\Models\WorkItem;

/**
 * Turns the lab tests on an order into laboratory work: one work item per
 * test, grouped onto one sample per sample type.
 *
 * Safe to run again for the same order; it only adds what is missing.
 */
final class WorkPlanner
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /** @return int how many work items were created */
    public function planFor(Order $order): int
    {
        return DB::connection('clinic')->transaction(function () use ($order): int {
            // Lock the order so two runs for it cannot both create the work.
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $items = $order->items()
                ->where('status', ItemStatus::Active->value)
                ->where('service_type', ServiceType::LabTest->value)
                ->whereNotIn('id', WorkItem::query()->select('order_item_id')->where('order_id', $order->id))
                ->get();

            if ($items->isEmpty()) {
                return 0;
            }

            $tests = LabTest::whereIn('service_id', $items->pluck('service_id'))->get()->keyBy('service_id');
            $created = 0;

            foreach ($items as $item) {
                // A lab test with no laboratory set-up cannot have results.
                // It is listed on the lab test screen until someone sets it up.
                if (! isset($tests[$item->service_id])) {
                    continue;
                }

                $test = $tests[$item->service_id];
                $sample = $this->sampleFor($order, $test->sample_type_id);
                $inLab = in_array($sample->status, [SampleStatus::Received, SampleStatus::InProcess], true);

                WorkItem::create([
                    'order_item_id' => $item->id,
                    'order_id' => $order->id,
                    'lab_test_id' => $test->id,
                    'sample_id' => $sample->id,
                    'patient_id' => $order->patient_id,
                    'branch_id' => $order->branch_id,
                    'lab_branch_id' => $sample->labBranchId(),
                    'test_name' => $item->service_name,
                    'department' => $test->department,
                    'status' => $inLab ? WorkStatus::Ready : WorkStatus::AwaitingSample,
                ]);

                $created++;
            }

            return $created;
        });
    }

    /** A sample of this type that can still take another test, or a new one. */
    private function sampleFor(Order $order, int $sampleTypeId): Sample
    {
        $open = Sample::query()
            ->where('order_id', $order->id)
            ->where('sample_type_id', $sampleTypeId)
            ->whereIn('status', [
                SampleStatus::Pending->value, SampleStatus::Collected->value, SampleStatus::InTransit->value, SampleStatus::Received->value,
                SampleStatus::InProcess->value, SampleStatus::Rejected->value,
            ])
            ->orderBy('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $sample = Sample::create([
            'accession_no' => $this->numbers->generate('accession', $order->branch),
            'order_id' => $order->id,
            'patient_id' => $order->patient_id,
            'sample_type_id' => $sampleTypeId,
            'branch_id' => $order->branch_id,
            'status' => SampleStatus::Pending,
            'lab_branch_id' => app(LabRoutes::class)->labFor((int) $order->branch_id),
        ]);

        SampleEvent::create(['sample_id' => $sample->id, 'event' => 'created', 'actor_id' => Auth::id(), 'occurred_at' => now()]);

        return $sample;
    }
}
