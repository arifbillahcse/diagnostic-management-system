<?php

declare(strict_types=1);

namespace Modules\Laboratory\Console;

use App\Core\Branches\Actions\SaveBranch;
use App\Core\Branches\Branch;
use App\Core\Console\SampleDataCommand as Base;
use App\Core\Modules\ModuleState;
use App\Core\Patients\Patient;
use App\Core\Tenancy\Clinic;
use Carbon\CarbonImmutable;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Service;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\SaveLabRoutes;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\Transfer;
use RuntimeException;

/**
 * Sample data for a second branch and a collection centre: a "North branch"
 * that takes samples and sends them to the main laboratory, with one transfer
 * already received and one still on its way.
 *
 * Needs the Multi-branch module. Run it after laboratory:sample-data.
 */
final class SampleBranchesCommand extends Base
{
    protected $signature = 'laboratory:sample-branches
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load a second branch that sends its samples to the main laboratory, for trying branches and collection centres';

    protected function moduleKey(): string
    {
        return 'laboratory';
    }

    protected function load(Clinic $clinic): array
    {
        if (! app(ModuleState::class)->isEnabled('multibranch')) {
            throw new RuntimeException('The multi-branch module is switched off for this clinic. Turn it on from the Modules page first.');
        }

        if (Branch::count() > 1 || Transfer::query()->exists()) {
            throw new RuntimeException('This clinic already has more than one branch or a sample transfer. Sample data is only for a clinic with a single branch.');
        }

        $main = Branch::orderBy('id')->firstOrFail();
        $services = Service::whereIn('code', ['CBC', 'LIPID', 'FBS', 'URINE', 'TSH'])->pluck('id', 'code');
        $patients = Patient::orderBy('id')->limit(6)->get();

        if ($services->count() < 5 || $patients->count() < 6) {
            throw new RuntimeException('The sample tests and patients are missing. Run laboratory:sample-data first.');
        }

        $north = app(SaveBranch::class)->create(['code' => 'NTH', 'name' => 'North branch', 'address' => 'Sonadanga, Khulna', 'phone' => '01700111222']);
        app(SaveLabRoutes::class)->handle([$north->id => $main->id]);

        $today = CarbonImmutable::now($clinic->timezone)->startOfDay();
        $baskets = [['CBC', 'FBS'], ['LIPID'], ['URINE', 'CBC'], ['TSH'], ['CBC'], ['LIPID', 'FBS']];

        // Yesterday: three bills, samples collected and sent in one box, received in the main lab.
        foreach (array_slice($baskets, 0, 3) as $i => $codes) {
            $this->travelTo($today->subDay()->addHours(10)->addMinutes(12 * $i));
            $this->bill($patients[$i], $north, $codes, $services, $i);
        }

        $this->travelTo($today->subDay()->addHours(16));
        $received = app(HandleSample::class)->send($north, $main, null, 'Courier: Rahim, box 1');
        $this->travelTo($today->subDay()->addHours(18));
        app(HandleSample::class)->receiveTransfer($received);

        // Today: three more bills, sent this afternoon and still on the way.
        foreach (array_slice($baskets, 3) as $i => $codes) {
            $this->travelTo($today->addHours(9)->addMinutes(15 * $i));
            $this->bill($patients[$i + 3], $north, $codes, $services, $i + 3);
        }

        $this->backToNow();
        $onTheWay = app(HandleSample::class)->send($north, $main, null, 'Courier: Rahim, box 2');

        return [
            '1 second branch: North branch, whose samples are tested at the main branch (Branches, Collection centres)',
            "6 bills at North branch; {$received->transfer_no} was received yesterday, {$onTheWay->transfer_no} is still on the way (Sample transfers)",
            'Use the branch switcher at the top of any screen to see each branch on its own',
        ];
    }

    /** @param list<string> $codes @param \Illuminate\Support\Collection<string, int> $services */
    private function bill(Patient $patient, Branch $branch, array $codes, $services, int $number): void
    {
        $order = app(PlaceOrder::class)->handle($patient, $branch, array_map(fn (string $c): int => $services[$c], $codes));
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        app(RecordPayment::class)->handle($invoice, $invoice->total, [PaymentMethod::Bkash, PaymentMethod::Nagad][$number % 2], 'NTH'.str_pad((string) $number, 4, '0', STR_PAD_LEFT));

        foreach (Sample::where('order_id', $order->id)->get() as $sample) {
            app(HandleSample::class)->collect($sample);
        }
    }
}
