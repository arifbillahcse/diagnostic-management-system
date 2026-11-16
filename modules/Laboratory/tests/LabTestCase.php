<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Auth\User;
use App\Core\Patients\Patient;
use Carbon\CarbonImmutable;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Enums\ResultType;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\SampleType;
use Modules\Laboratory\Models\WorkItem;

/**
 * Shared set-up for laboratory tests: a small catalog (a blood count with
 * sex- and age-specific ranges, a glucose, a urine test with choices) and
 * helpers that walk an order through the lab.
 */
abstract class LabTestCase extends FrontDeskTestCase
{
    protected SampleType $edta;

    protected SampleType $urineCup;

    protected Service $cbc;

    protected Service $glucose;

    protected Service $urine;

    protected Analyte $hb;

    protected Analyte $wbc;

    protected User $technician;

    protected User $verifierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edta = SampleType::create(['name' => 'Blood (EDTA)', 'container' => 'Lavender top', 'label_copies' => 2]);
        $this->urineCup = SampleType::create(['name' => 'Urine', 'container' => 'Sterile cup']);

        $this->hb = $this->analyte('HB', 'Haemoglobin', 'g/dL', 1, [
            ['sex' => 'male', 'age_min_days' => 6570, 'low' => '13.0', 'high' => '17.0', 'critical_low' => '7.0', 'critical_high' => '20.0'],
            ['sex' => 'female', 'age_min_days' => 6570, 'low' => '12.0', 'high' => '15.0', 'critical_low' => '7.0', 'critical_high' => '20.0'],
            ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 6569, 'low' => '11.0', 'high' => '14.0'],
        ]);
        $this->wbc = $this->analyte('WBC', 'Total WBC count', '/cumm', 0, [['low' => '4000', 'high' => '11000']]);
        $rbs = $this->analyte('RBS', 'Random blood sugar', 'mmol/L', 1, [['high' => '7.8']]);

        $colour = Analyte::create(['code' => 'UCOL', 'name' => 'Colour', 'result_type' => ResultType::Text]);
        $sugar = Analyte::create([
            'code' => 'USUG', 'name' => 'Sugar', 'result_type' => ResultType::Option,
            'options' => ['Nil', 'Trace', '+', '++'], 'abnormal_options' => ['Trace', '+', '++'],
        ]);
        $remarks = Analyte::create(['code' => 'UREM', 'name' => 'Remarks', 'result_type' => ResultType::LongText]);

        $this->cbc = $this->labService('Complete blood count', '400', $this->edta, 'Haematology', [$this->hb, $this->wbc]);
        $this->glucose = $this->labService('Random blood sugar', '150', $this->edta, 'Biochemistry', [$rbs]);
        $this->urine = $this->labService('Urine R/E', '200', $this->urineCup, 'Clinical pathology', [$colour, $sugar, [$remarks, false]]);

        $this->technician = $this->staff('lab_technician');
        $this->verifierUser = $this->staff('verifier');
        $this->actingAs($this->ownerUser);
    }

    /** @param list<array<string, mixed>> $ranges */
    protected function analyte(string $code, string $name, string $unit, int $decimals, array $ranges = []): Analyte
    {
        $analyte = Analyte::create(['code' => $code, 'name' => $name, 'result_type' => ResultType::Numeric, 'unit' => $unit, 'decimals' => $decimals]);

        foreach ($ranges as $range) {
            $analyte->ranges()->create($range);
        }

        return $analyte;
    }

    /** @param list<Analyte|array{0: Analyte, 1: bool}> $analytes */
    protected function labService(string $name, string $price, SampleType $type, string $department, array $analytes): Service
    {
        $service = $this->service($name, $price);
        $test = LabTest::create(['service_id' => $service->id, 'sample_type_id' => $type->id, 'department' => $department]);

        foreach ($analytes as $i => $analyte) {
            [$model, $required] = is_array($analyte) ? $analyte : [$analyte, true];
            $test->analyteLinks()->create(['analyte_id' => $model->id, 'sort_order' => $i, 'is_required' => $required]);
        }

        return $service;
    }

    protected function labPatient(string $sex = 'male', string $dob = '1990-05-20'): Patient
    {
        $patient = $this->patient('Patient '.uniqid());
        $patient->forceFill(['sex' => $sex, 'date_of_birth' => $dob])->save();

        return $patient;
    }

    protected function labOrder(array $services, ?Patient $patient = null): Order
    {
        $acting = auth()->user();
        $this->actingAs($this->ownerUser);

        $order = app(PlaceOrder::class)->handle(
            $patient ?? $this->labPatient(),
            $this->branch,
            array_map(fn (Service $s): int => $s->id, $services),
        );

        $this->actingAs($acting);

        return $order;
    }

    protected function work(Order $order, Service $service): WorkItem
    {
        return WorkItem::where('order_id', $order->id)->where('test_name', $service->name)->firstOrFail();
    }

    /** Collect and receive every sample of the order. */
    protected function receiveSamples(Order $order): void
    {
        foreach (Sample::where('order_id', $order->id)->get() as $sample) {
            app(HandleSample::class)->collect($sample, alsoReceive: true);
        }
    }

    /** @param array<string, string> $byCode analyte code => typed value */
    protected function enter(WorkItem $item, array $byCode, bool $submit = true, ?User $as = null): WorkItem
    {
        $this->actingAs($as ?? $this->technician);
        $item = $item->fresh();
        $values = [];

        foreach ($byCode as $code => $value) {
            $values[Analyte::where('code', $code)->valueOrFail('id')] = ['value' => $value];
        }

        return app(EnterResults::class)->handle($item, $values, $item->lock_version, $submit);
    }

    protected function verify(WorkItem $item, ?User $as = null): WorkItem
    {
        $this->actingAs($as ?? $this->verifierUser);

        return app(VerifyResults::class)->verify($item->fresh());
    }

    /** An order taken all the way to a released blood count. */
    protected function releasedCbc(string $hb = '14.2', string $wbc = '7500', ?Patient $patient = null): WorkItem
    {
        $order = $this->labOrder([$this->cbc], $patient);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => $hb, 'WBC' => $wbc]);

        return $this->verify($this->work($order, $this->cbc));
    }

    protected function at(string $dhakaTime): void
    {
        $this->travelTo(CarbonImmutable::parse($dhakaTime, 'Asia/Dhaka'));
    }
}
