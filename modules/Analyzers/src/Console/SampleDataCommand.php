<?php

declare(strict_types=1);

namespace Modules\Analyzers\Console;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Console\SampleDataCommand as Base;
use App\Core\Patients\Patient;
use App\Core\Tenancy\Clinic;
use Illuminate\Support\Facades\Auth;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Actions\ReceiveResults;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Mapping;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Models\Service;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\Sample;
use RuntimeException;

/**
 * Sample data for the lab machines: two machines with their test codes
 * mapped, and received messages in each state (saved, partly saved, no
 * sample found, unreadable). It also leaves one blood sample waiting in the
 * lab, so you can send its result yourself with "Try a message" or with the
 * bridge's pretend analyzer.
 *
 * Run it after laboratory:sample-data.
 */
final class SampleDataCommand extends Base
{
    protected $signature = 'analyzers:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load two sample lab machines with mapped codes and received messages, for trying the machine inbox';

    /** machine code => analyte code */
    private const BLOOD_CODES = ['WBC' => 'WBC', 'HGB' => 'HB', 'PLT' => 'PLT'];

    private const CHEMISTRY_CODES = ['GLU' => 'FBS', 'CHOL' => 'CHOL', 'TRIG' => 'TG', 'HDL' => 'HDL', 'LDL' => 'LDL', 'CREA' => 'CREA'];

    protected function moduleKey(): string
    {
        return 'analyzers';
    }

    protected function load(Clinic $clinic): array
    {
        if (Machine::query()->exists()) {
            throw new RuntimeException('This clinic already has lab machines. Sample data is only for a clinic without any.');
        }

        $owner = Auth::user();
        $operator = User::whereHas('roles', fn ($q) => $q->where('key', 'lab_technician'))->first() ?? $owner;
        $branch = Branch::orderBy('id')->firstOrFail();
        $services = Service::whereIn('code', ['CBC', 'LIPID', 'FBS'])->pluck('id', 'code');

        if ($services->count() < 3) {
            throw new RuntimeException('The sample tests (CBC, LIPID, FBS) are missing. Run laboratory:sample-data first.');
        }

        [$blood, $bloodKey] = app(ManageMachines::class)->add(['name' => 'Haematology analyzer (5-part)', 'model' => 'Sample 5-part', 'protocol' => 'hl7', 'branch_id' => $branch->id, 'user_id' => $operator->id]);
        [$chem] = app(ManageMachines::class)->add(['name' => 'Biochemistry analyzer', 'model' => 'Sample chemistry', 'protocol' => 'astm', 'branch_id' => $branch->id, 'user_id' => $operator->id]);

        foreach ([[$blood, self::BLOOD_CODES], [$chem, self::CHEMISTRY_CODES]] as [$machine, $codes]) {
            foreach ($codes as $machineCode => $analyteCode) {
                Mapping::create(['machine_id' => $machine->id, 'machine_code' => $machineCode, 'analyte_id' => Analyte::where('code', $analyteCode)->firstOrFail()->id]);
            }
        }

        // Three new bills at the counter, the samples already in the lab.
        $patients = Patient::orderBy('id')->limit(3)->get();
        $samples = [];

        foreach ([['CBC'], ['LIPID', 'FBS'], ['CBC']] as $i => $codes) {
            $order = app(PlaceOrder::class)->handle($patients[$i], $branch, array_map(fn (string $c): int => $services[$c], $codes));

            foreach (Sample::where('order_id', $order->id)->get() as $sample) {
                app(HandleSample::class)->collect($sample, alsoReceive: true);
                $samples[$i][] = $sample->accession_no;
            }
        }

        $receive = app(ReceiveResults::class);

        // 1. A full blood count with more codes than are mapped: part is saved, the rest waits in the inbox.
        $receive->receive($blood, $this->bloodMessage($samples[0][0], ['WBC' => ['7400', '/cumm'], 'HGB' => ['13.1', 'g/dL'], 'PLT' => ['236000', '/cumm'], 'RBC' => ['4.6', '10*12/L'], 'HCT' => ['39.8', '%'], 'MCV' => ['86.5', 'fL'], 'RDW-CV' => ['13.2', '%']]));

        // 2. Chemistry results for a bill with a lipid profile and a sugar test: all saved.
        $receive->receive($chem, $this->chemistryMessage($samples[1][0], ['CHOL' => ['238', 'mg/dL'], 'TRIG' => ['182', 'mg/dL'], 'HDL' => ['38', 'mg/dL'], 'LDL' => ['164', 'mg/dL']]));

        // 3. A sample number that no bill has: it waits for staff to match it by hand.
        $receive->receive($blood, $this->bloodMessage('S99999', ['WBC' => ['6800', '/cumm'], 'HGB' => ['12.4', 'g/dL'], 'PLT' => ['210000', '/cumm']]));

        // 4. Something that is not a result message at all.
        $receive->receive($blood, 'Self-test OK. Reagent level low. Not a result message.');

        return [
            '2 machines with test codes mapped (Lab machines, Test codes)',
            '4 received messages: 1 partly saved, 1 saved, 1 sample not found, 1 unreadable (Machine inbox)',
            "Sample {$samples[2][0]} is a blood count waiting in the lab for its result:",
            '  try it with "Try a message" on the Machines screen, or with the bridge simulator:',
            "  python -m dms_bridge.simulator --connect 127.0.0.1:5100 --sample {$samples[2][0]} --units cumm",
            "Key of the haematology machine, for the bridge (shown only now): {$bloodKey}",
        ];
    }

    /** @param array<string, array{0: string, 1: string}> $values */
    private function bloodMessage(string $sample, array $values): string
    {
        $segments = ['MSH|^~\\&|SAMPLE|LAB|LIS|DMS|'.now()->format('YmdHis').'||ORU^R01|'.$sample.'|P|2.3.1', "PID|1||{$sample}||Sample^Patient", "OBR|1||{$sample}|00001^Automated Count^99MRC"];
        $i = 0;

        foreach ($values as $code => [$value, $unit]) {
            $segments[] = 'OBX|'.++$i."|NM|{$code}||{$value}|{$unit}|||||F";
        }

        return implode("\r", $segments)."\r";
    }

    /** @param array<string, array{0: string, 1: string}> $values */
    private function chemistryMessage(string $sample, array $values): string
    {
        $records = ['H|\\^&|||Sample chemistry analyzer', "O|1|{$sample}||^^^PANEL"];
        $i = 0;

        foreach ($values as $code => [$value, $unit]) {
            $records[] = 'R|'.++$i."|^^^{$code}|{$value}|{$unit}||N";
        }

        return implode("\r", $records)."\rL|1|N";
    }
}
