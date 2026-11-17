<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Models\Service;
use Modules\Inventory\Actions\ManagePurchases;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Models\Usage;
use RuntimeException;
use Throwable;

/**
 * Sample stock for trying the inventory module: items, a supplier, one
 * purchase, and stock use rules for the sample tests that exist.
 */
final class SampleDataCommand extends Command
{
    protected $signature = 'inventory:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load sample stock items, a supplier, a purchase and stock use rules for trying the inventory module';

    /** code => [name, category, unit, reorder level, tracks expiry, quantity bought, unit cost, batch] */
    private const ITEMS = [
        'EDTA' => ['EDTA tube', 'consumable', 'piece', '50', false, '300', '6.00', null],
        'NEEDLE' => ['Needle 23G', 'consumable', 'piece', '100', false, '500', '2.50', null],
        'SYR5' => ['Syringe 5 ml', 'consumable', 'piece', '100', false, '400', '4.00', null],
        'URCUP' => ['Urine container', 'consumable', 'piece', '30', false, '150', '5.00', null],
        'GLU-R' => ['Glucose reagent', 'reagent', 'ml', '100', true, '500', '1.20', 'GL2611'],
        'LIP-R' => ['Lipid profile reagent kit', 'reagent', 'test', '20', true, '100', '45.00', 'LP2611'],
        'XFILM' => ['X-ray film 14x17 in', 'consumable', 'sheet', '25', true, '100', '60.00', 'XF2611'],
    ];

    /** service code => [item code => quantity per test] */
    private const RULES = [
        'CBC' => ['EDTA' => '1', 'NEEDLE' => '1', 'SYR5' => '1'],
        'RBS' => ['GLU-R' => '2', 'NEEDLE' => '1', 'SYR5' => '1'],
        'FBS' => ['GLU-R' => '2', 'NEEDLE' => '1', 'SYR5' => '1'],
        'LIPID' => ['LIP-R' => '1', 'NEEDLE' => '1', 'SYR5' => '1'],
        'URINE' => ['URCUP' => '1'],
        'XRAY-CHEST' => ['XFILM' => '1'],
    ];

    public function handle(ClinicManager $manager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This server is in production. Add --force only for a clinic that will never hold real data.');

            return self::FAILURE;
        }

        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Load sample stock into \"{$clinic->name}\"? It cannot be removed afterwards.")) {
            return self::FAILURE;
        }

        try {
            [$items, $rules] = $manager->run($clinic, fn (): array => $this->load($clinic));
        } catch (Throwable $e) {
            $this->error("Sample stock was not loaded: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        $this->info("Sample stock loaded into \"{$clinic->name}\": {$items} items, 1 supplier, 1 purchase (unpaid), {$rules} stock use rules.");

        if ($rules === 0) {
            $this->warn('No stock use rules were made: none of the sample tests (CBC, RBS, FBS, LIPID, URINE) exist. Run laboratory:sample-data first, or add rules on the "Stock use per test" screen.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function load(Clinic $clinic): array
    {
        if (! app(ModuleState::class)->isEnabled('inventory')) {
            throw new RuntimeException('The inventory module is switched off for this clinic. Turn it on from the Modules page first.');
        }
        if (Item::query()->exists()) {
            throw new RuntimeException('This clinic already has stock items. Sample stock is only for a clinic without any.');
        }

        Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());

        $today = CarbonImmutable::now($clinic->timezone);
        $lines = [];

        foreach (self::ITEMS as $code => [$name, $category, $unit, $reorder, $expiry, $quantity, $cost, $batch]) {
            $item = Item::create(['code' => $code, 'name' => $name, 'category' => $category, 'unit' => $unit, 'reorder_level' => $reorder, 'tracks_expiry' => $expiry]);
            $lines[] = array_filter([
                'item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $cost,
                'batch_no' => $batch, 'expires_on' => $expiry ? $today->addMonths(10)->toDateString() : null,
            ], fn ($v): bool => $v !== null);
        }

        $supplier = Supplier::create(['name' => 'Popular Diagnostics Supply', 'phone' => '01700000000', 'address' => 'Mirpur, Dhaka']);
        app(ManagePurchases::class)->record(Branch::firstOrFail(), $supplier, $today->subDays(7)->toDateString(), $lines, 'PDS-1021');

        $rules = 0;
        $items = Item::pluck('id', 'code');

        foreach (self::RULES as $serviceCode => $uses) {
            $service = Service::where('code', $serviceCode)->first();

            foreach ($service ? $uses : [] as $itemCode => $quantity) {
                Usage::create(['service_id' => $service->id, 'item_id' => $items[$itemCode], 'quantity' => $quantity]);
                $rules++;
            }
        }

        return [count(self::ITEMS), $rules];
    }
}
