<?php

declare(strict_types=1);

namespace Modules\Analyzers\Tests;

use App\Core\Modules\ModuleManager;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Mapping;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Tests\LabTestCase;

/** The laboratory set-up with lab machine integration on and one mapped machine. */
abstract class AnalyzerTestCase extends LabTestCase
{
    protected const BASE = 'http://alpha.test';

    protected Machine $machine;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('analyzers');
        $this->actingAs($this->ownerUser);
        [$this->machine, $this->key] = $this->machine('Haematology analyzer', 'astm');
    }

    /** @return array{0: Machine, 1: string} */
    protected function machine(string $name, string $protocol): array
    {
        [$machine, $key] = app(ManageMachines::class)->add([
            'name' => $name, 'protocol' => $protocol, 'branch_id' => $this->branch->id, 'user_id' => $this->technician->id,
        ]);

        foreach (['HGB' => $this->hb->id, 'WBC' => $this->wbc->id] as $code => $analyteId) {
            Mapping::create(['machine_id' => $machine->id, 'machine_code' => $code, 'analyte_id' => $analyteId]);
        }

        return [$machine, $key];
    }

    /** A CBC order whose sample is in the laboratory. */
    protected function cbcInLab(): Order
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);

        return $order;
    }

    protected function sampleNo(Order $order): string
    {
        return Sample::where('order_id', $order->id)->firstOrFail()->accession_no;
    }

    protected function fixture(string $name, string $sample): string
    {
        return str_replace(['{SAMPLE}', "\n"], [$sample, "\r"], trim((string) file_get_contents(__DIR__.'/fixtures/'.$name)));
    }
}
