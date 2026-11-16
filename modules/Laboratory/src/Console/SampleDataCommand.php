<?php

declare(strict_types=1);

namespace Modules\Laboratory\Console;

use App\Core\Auth\Actions\SaveUser;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Money\Money;
use App\Core\Patients\Actions\SavePatient;
use App\Core\Patients\Patient;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\FrontDesk\Actions\ManageCashSession;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Enums\ResultType;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\SampleType;
use Modules\Laboratory\Models\WorkItem;
use RuntimeException;
use Throwable;

/**
 * Fills a new clinic with a small, realistic set of sample data for trying
 * the software: a test catalog with reference ranges, supplies charged with tests, rooms, referral doctors,
 * three staff accounts, patients, and ten days of orders at every stage.
 *
 * Everything except the catalog goes through the same actions the screens
 * use, so numbers, invoices, the audit trail, and the lab workflow are real.
 * The reference ranges are examples only and must be reviewed by the
 * laboratory before any real use.
 */
final class SampleDataCommand extends Command
{
    protected $signature = 'laboratory:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--password= : Password for the three sample staff accounts; one is generated when omitted}
        {--days=10 : How many days of history, ending today}
        {--force : Do not ask for confirmation}';

    protected $description = 'Load a new clinic with sample catalog, staff, patients, and orders for trying the software';

    private const NUMERIC = [
        // code => [name, unit, decimals, ranges, normal value, abnormal value]
        'HB' => ['Haemoglobin', 'g/dL', 1, [
            ['sex' => 'male', 'age_min_days' => 6570, 'low' => '13.0', 'high' => '17.0', 'critical_low' => '7.0', 'critical_high' => '20.0'],
            ['sex' => 'female', 'age_min_days' => 6570, 'low' => '12.0', 'high' => '15.0', 'critical_low' => '7.0', 'critical_high' => '20.0'],
            ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 6569, 'low' => '11.0', 'high' => '14.0'],
        ], '13.6', '9.8'],
        'WBC' => ['Total WBC count', '/cumm', 0, [['low' => '4000', 'high' => '11000']], '7500', '13500'],
        'PLT' => ['Platelet count', '/cumm', 0, [['low' => '150000', 'high' => '450000']], '250000', '120000'],
        'ESR' => ['ESR (Westergren)', 'mm in 1st hour', 0, [['sex' => 'male', 'high' => '15'], ['sex' => 'female', 'high' => '20']], '10', '45'],
        'RBS' => ['Random blood sugar', 'mmol/L', 1, [['high' => '7.8']], '6.2', '11.4'],
        'FBS' => ['Fasting blood sugar', 'mmol/L', 1, [['low' => '3.9', 'high' => '6.1']], '5.2', '8.9'],
        'HBA1C' => ['HbA1c', '%', 1, [['high' => '5.7']], '5.4', '8.2'],
        'CHOL' => ['Total cholesterol', 'mg/dL', 0, [['high' => '200']], '180', '260'],
        'TG' => ['Triglycerides', 'mg/dL', 0, [['high' => '150']], '130', '240'],
        'HDL' => ['HDL cholesterol', 'mg/dL', 0, [['low' => '40']], '48', '35'],
        'LDL' => ['LDL cholesterol', 'mg/dL', 0, [['high' => '130']], '110', '165'],
        'CREA' => ['Serum creatinine', 'mg/dL', 2, [['sex' => 'male', 'low' => '0.70', 'high' => '1.30'], ['sex' => 'female', 'low' => '0.60', 'high' => '1.10']], '0.90', '1.80'],
        'SGPT' => ['SGPT (ALT)', 'U/L', 0, [['high' => '40']], '28', '75'],
        'TSH' => ['TSH', 'µIU/mL', 2, [['low' => '0.40', 'high' => '4.00']], '2.10', '7.50'],
    ];

    private const OTHER_VALUES = [
        'UCOL' => ['Straw', 'Dark yellow'],
        'USUG' => ['Nil', '+'],
        'UALB' => ['Nil', 'Trace'],
        'UPUS' => ['1-2 /HPF', '8-10 /HPF'],
    ];

    /** Combinations of service codes ordered in turn. */
    private const BASKETS = [
        ['CBC'], ['CBC', 'RBS'], ['LIPID', 'FBS'], ['URINE'], ['CREA', 'SGPT'], ['TSH'], ['PKG-BASIC'],
        ['CBC', 'XRAY-CHEST'], ['HBA1C', 'FBS'], ['USG-ABD'], ['CBC', 'URINE', 'ECG'], ['LIPID', 'CREA', 'TSH'],
    ];

    private const PATIENTS = [
        ['Abdul Karim', 'male', 52], ['Salma Akter', 'female', 34], ['Rafiqul Islam', 'male', 45], ['Nasrin Sultana', 'female', 28],
        ['Jamal Uddin', 'male', 61], ['Rina Begum', 'female', 47], ['Tanvir Ahmed', 'male', 23], ['Sumaiya Khatun', 'female', 9],
        ['Habibur Rahman', 'male', 70], ['Laila Chowdhury', 'female', 39], ['Imran Hossain', 'male', 31], ['Ayesha Siddika', 'female', 56],
        ['Sohel Rana', 'male', 18], ['Mitu Sarkar', 'female', 25], ['Faruk Miah', 'male', 66], ['Nadia Islam', 'female', 42],
    ];

    private bool $drawerOpen = false;

    public function handle(ClinicManager $manager): int
    {
        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(14, symbols: false));

        if (validator(['password' => $password], ['password' => [Password::defaults()]])->fails()) {
            $this->error('The password is too weak. Use at least 10 characters.');

            return self::FAILURE;
        }

        $days = max(1, min(60, (int) $this->option('days')));

        if (! $this->option('force') && ! $this->confirm("Load sample data into \"{$clinic->name}\"? It cannot be removed afterwards.")) {
            return self::FAILURE;
        }

        try {
            $counts = $manager->run($clinic, fn (): array => $this->load($clinic, $password, $days));
        } catch (Throwable $e) {
            $this->error("Sample data was not fully loaded: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Auth::forgetUser();
        }

        Artisan::call('frontdesk:summarize', ['--days' => $days + 1, '--clinic' => $clinic->id]);

        $this->info("Sample data loaded into \"{$clinic->name}\".");
        $this->line("  Services: {$counts['services']}   Doctors: {$counts['doctors']}   Patients: {$counts['patients']}   Orders: {$counts['orders']}");
        $this->newLine();
        $this->line('  Sample staff accounts (password: '.$password.')');

        foreach ($counts['staff'] as $role => $email) {
            $this->line("    {$role}: {$email}");
        }

        $this->newLine();
        $this->warn('The reference ranges are examples. The laboratory must review them before real use.');

        return self::SUCCESS;
    }

    /** @return array{services: int, doctors: int, patients: int, orders: int, staff: array<string, string>} */
    private function load(Clinic $clinic, string $password, int $days): array
    {
        if (! app(ModuleState::class)->isEnabled('laboratory')) {
            throw new RuntimeException('The laboratory module is switched off for this clinic.');
        }

        if (Service::query()->exists() || Order::query()->exists()) {
            throw new RuntimeException('This clinic already has services or orders. Sample data is only for a new clinic.');
        }

        $owner = User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail();
        $branch = Branch::firstOrFail();
        Auth::setUser($owner);

        $services = $this->catalog();
        $doctors = $this->doctors();
        $staff = $this->staff($clinic, $branch, $password);

        $today = CarbonImmutable::now($clinic->timezone)->startOfDay();
        $patients = [];
        $number = 0;

        for ($back = $days - 1; $back >= 0; $back--) {
            $day = $today->subDays($back);

            foreach ([9, 11, 15] as $slot => $hour) {
                // Today's orders are placed "this morning", never in the future.
                $at = $back === 0 ? min($day->addHours($hour), CarbonImmutable::now($clinic->timezone)->subMinutes(30 - 10 * $slot)) : $day->addHours($hour)->addMinutes(7 * $number % 50);
                $this->setNow($at);

                Auth::setUser($staff['users']['receptionist']);
                $patient = $patients[$number % count(self::PATIENTS)] ??= $this->patient($number % count(self::PATIENTS), $branch);
                $order = $this->order($patient, $branch, $services, $doctors, $number, $back === 0);

                $this->lab($order, $staff['users'], $back, $slot, abnormal: $number % 4 === 3, at: $at);
                $number++;
            }
        }

        return [
            'services' => count($services),
            'doctors' => count($doctors),
            'patients' => count($patients),
            'orders' => $number,
            'staff' => $staff['emails'],
        ];
    }

    /** @return array<string, Service> services by code */
    private function catalog(): array
    {
        $edta = SampleType::create(['name' => 'Blood (EDTA)', 'container' => 'Lavender top', 'label_copies' => 1]);
        $serum = SampleType::create(['name' => 'Serum', 'container' => 'Red top', 'label_copies' => 1]);
        $fluoride = SampleType::create(['name' => 'Plasma (fluoride)', 'container' => 'Grey top', 'label_copies' => 1]);
        $urine = SampleType::create(['name' => 'Urine', 'container' => 'Sterile cup', 'label_copies' => 1]);

        $analytes = [];

        foreach (self::NUMERIC as $code => [$name, $unit, $decimals, $ranges]) {
            $analytes[$code] = Analyte::create(['code' => $code, 'name' => $name, 'result_type' => ResultType::Numeric, 'unit' => $unit, 'decimals' => $decimals]);

            foreach ($ranges as $range) {
                $analytes[$code]->ranges()->create($range);
            }
        }

        $analytes['UCOL'] = Analyte::create(['code' => 'UCOL', 'name' => 'Colour', 'result_type' => ResultType::Text]);
        $analytes['USUG'] = Analyte::create(['code' => 'USUG', 'name' => 'Sugar', 'result_type' => ResultType::Option, 'options' => ['Nil', 'Trace', '+', '++', '+++'], 'abnormal_options' => ['Trace', '+', '++', '+++']]);
        $analytes['UALB'] = Analyte::create(['code' => 'UALB', 'name' => 'Albumin', 'result_type' => ResultType::Option, 'options' => ['Nil', 'Trace', '+', '++', '+++'], 'abnormal_options' => ['Trace', '+', '++', '+++']]);
        $analytes['UPUS'] = Analyte::create(['code' => 'UPUS', 'name' => 'Pus cells', 'result_type' => ResultType::Text]);

        $categories = [];

        // The room is printed on the invoice; blood and urine tests share one.
        foreach ([
            'Imaging' => 'USG (Room 210)',
            'Haematology' => 'Sample collection (Room 106)',
            'Biochemistry' => 'Sample collection (Room 106)',
            'Hormone' => 'Sample collection (Room 106)',
            'Clinical pathology' => 'Sample collection (Room 106)',
            'Packages' => null,
            'Other' => null,
        ] as $name => $room) {
            $categories[$name] = ServiceCategory::create(['name' => $name, 'sort_order' => count($categories), 'room' => $room]);
        }

        $services = [];

        // code => [name, price, category, sample type, analyte codes]
        $labTests = [
            'CBC' => ['Complete blood count (CBC)', '400', 'Haematology', $edta, ['HB', 'WBC', 'PLT', 'ESR']],
            'RBS' => ['Random blood sugar', '150', 'Biochemistry', $fluoride, ['RBS']],
            'FBS' => ['Fasting blood sugar', '150', 'Biochemistry', $fluoride, ['FBS']],
            'HBA1C' => ['HbA1c', '1000', 'Biochemistry', $edta, ['HBA1C']],
            'LIPID' => ['Lipid profile', '1000', 'Biochemistry', $serum, ['CHOL', 'TG', 'HDL', 'LDL']],
            'CREA' => ['Serum creatinine', '400', 'Biochemistry', $serum, ['CREA']],
            'SGPT' => ['SGPT (ALT)', '400', 'Biochemistry', $serum, ['SGPT']],
            'TSH' => ['TSH', '800', 'Hormone', $serum, ['TSH']],
            'URINE' => ['Urine R/E', '200', 'Clinical pathology', $urine, ['UCOL', 'USUG', 'UALB', 'UPUS']],
        ];

        foreach ($labTests as $code => [$name, $price, $category, $type, $codes]) {
            $services[$code] = Service::create(['code' => $code, 'name' => $name, 'type' => ServiceType::LabTest, 'category_id' => $categories[$category]->id, 'price' => $price]);
            $test = LabTest::create(['service_id' => $services[$code]->id, 'sample_type_id' => $type->id, 'department' => $category, 'turnaround_hours' => 24]);

            foreach ($codes as $i => $analyteCode) {
                $test->analyteLinks()->create(['analyte_id' => $analytes[$analyteCode]->id, 'sort_order' => $i, 'is_required' => true]);
            }
        }

        $services['XRAY-CHEST'] = Service::create(['code' => 'XRAY-CHEST', 'name' => 'X-ray chest P/A view', 'type' => ServiceType::Imaging, 'category_id' => $categories['Imaging']->id, 'price' => '500']);
        $services['USG-ABD'] = Service::create(['code' => 'USG-ABD', 'name' => 'Ultrasonogram of whole abdomen', 'type' => ServiceType::Imaging, 'category_id' => $categories['Imaging']->id, 'price' => '1500']);
        $services['ECG'] = Service::create(['code' => 'ECG', 'name' => 'ECG', 'type' => ServiceType::Other, 'category_id' => $categories['Other']->id, 'price' => '400']);

        // Supplies charged with a test, once per order.
        foreach ([
            'NEEDLE' => ['Needle', '5'], 'TUBE-EDTA' => ['EDTA tube', '10'], 'TUBE-ESR' => ['ESR long tube', '10'],
            'TUBE-GLU' => ['Glucose tube', '10'], 'TUBE-URINE' => ['Urine conical tube', '5'],
        ] as $code => [$name, $price]) {
            $services[$code] = Service::create(['code' => $code, 'name' => $name, 'type' => ServiceType::Other, 'category_id' => $categories['Other']->id, 'price' => $price]);
        }

        foreach ([
            'CBC' => ['NEEDLE', 'TUBE-EDTA', 'TUBE-ESR'], 'RBS' => ['NEEDLE', 'TUBE-GLU'], 'FBS' => ['NEEDLE', 'TUBE-GLU'],
            'HBA1C' => ['NEEDLE', 'TUBE-EDTA'], 'LIPID' => ['NEEDLE'], 'CREA' => ['NEEDLE'], 'SGPT' => ['NEEDLE'],
            'TSH' => ['NEEDLE'], 'URINE' => ['TUBE-URINE'],
        ] as $code => $supplies) {
            $services[$code]->addons()->sync(array_map(fn (string $s): int => $services[$s]->id, $supplies));
        }

        $services['PKG-BASIC'] = Service::create(['code' => 'PKG-BASIC', 'name' => 'Basic health check', 'type' => ServiceType::Package, 'category_id' => $categories['Packages']->id, 'price' => '1000']);
        $services['PKG-BASIC']->packageItems()->sync([$services['CBC']->id, $services['FBS']->id, $services['CREA']->id, $services['URINE']->id]);

        return $services;
    }

    /** @return list<ReferralDoctor> */
    private function doctors(): array
    {
        return array_map(
            fn (array $d): ReferralDoctor => ReferralDoctor::create(['code' => $d[3], 'name' => $d[0], 'degree' => $d[1], 'phone' => $d[2]]),
            [
                ['Dr. Mahmudul Hasan', 'MBBS, FCPS (Medicine)', '01711000101', '001'],
                ['Dr. Farzana Rahman', 'MBBS, DGO', '01711000102', '002'],
                ['Dr. Anisur Rahman', 'MBBS, MD (Cardiology)', '01711000103', '003'],
                ['Dr. Shahana Parvin', 'MBBS, DCH', '01711000104', '004'],
                ['Dr. Kamrul Islam', 'MBBS, MS (Orthopaedics)', '01711000105', '005'],
            ],
        );
    }

    /** @return array{users: array<string, User>, emails: array<string, string>} */
    private function staff(Clinic $clinic, Branch $branch, string $password): array
    {
        $users = [];
        $emails = [];

        foreach (['receptionist' => 'Sample Receptionist', 'lab_technician' => 'Sample Technician', 'verifier' => 'Sample Verifier'] as $role => $name) {
            $email = str_replace('_', '.', $role)."@clinic{$clinic->id}.example";

            $users[$role] = app(SaveUser::class)->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
                'default_branch_id' => $branch->id,
                'role_ids' => [Role::where('key', $role)->valueOrFail('id')],
            ]);
            $emails[Str::headline($role)] = $email;
        }

        return ['users' => $users, 'emails' => $emails];
    }

    private function patient(int $index, Branch $branch): Patient
    {
        [$name, $sex, $age] = self::PATIENTS[$index];

        return app(SavePatient::class)->register([
            'name' => $name,
            'sex' => $sex,
            'age_years' => $age,
            'phone' => '017120001'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'address' => ['Mirpur, Dhaka', 'Uttara, Dhaka', 'Savar, Dhaka', 'Narayanganj'][$index % 4],
        ], $branch);
    }

    /**
     * @param  array<string, Service>  $services
     * @param  list<ReferralDoctor>  $doctors
     */
    private function order(Patient $patient, Branch $branch, array $services, array $doctors, int $number, bool $today): Order
    {
        $options = [];

        if ($number % 3 !== 2) {
            $options['referral_doctor_id'] = $doctors[$number % count($doctors)]->id;
        }

        if ($number % 7 === 5) {
            $options += ['discount_type' => 'percent', 'discount_value' => '5', 'discount_reason' => 'Regular patient'];
        }

        $order = app(PlaceOrder::class)->handle(
            $patient,
            $branch,
            array_map(fn (string $code): int => $services[$code]->id, self::BASKETS[$number % count(self::BASKETS)]),
            $options,
        );

        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();

        // In turn: paid in full, half paid (a due), nothing paid yet.
        $amount = match ($number % 5) {
            1 => $invoice->total->percent('50'),
            4 => null,
            default => $invoice->total,
        };

        if ($amount !== null && $amount->isPositive()) {
            if ($today) {
                // Cash needs an open drawer, so only today's takings are cash.
                if (! $this->drawerOpen) {
                    app(ManageCashSession::class)->open($branch, Money::fromDecimal('1000'));
                    $this->drawerOpen = true;
                }

                app(RecordPayment::class)->handle($invoice, $amount, PaymentMethod::Cash);
            } else {
                $method = [PaymentMethod::Bkash, PaymentMethod::Card, PaymentMethod::Nagad][$number % 3];
                app(RecordPayment::class)->handle($invoice, $amount, $method, 'SAMPLE'.str_pad((string) $number, 4, '0', STR_PAD_LEFT));
            }
        }

        return $order;
    }

    /**
     * Take the order's lab work as far as its age suggests: older orders
     * are finished, yesterday's wait for verification, today's are at
     * different points before results.
     *
     * @param  array<string, User>  $staff
     */
    private function lab(Order $order, array $staff, int $back, int $slot, bool $abnormal, CarbonImmutable $at): void
    {
        $samples = Sample::where('order_id', $order->id)->get();

        if ($samples->isEmpty() || ($back === 0 && $slot === 2)) {
            return; // nothing for the lab, or still waiting to be collected
        }

        $this->setNow($at->addMinutes(10));
        Auth::setUser($staff['lab_technician']);

        foreach ($samples as $sample) {
            // Today's second order is collected but has not reached the lab.
            app(HandleSample::class)->collect($sample, alsoReceive: ! ($back === 0 && $slot === 1));
        }

        if ($back === 0) {
            return;
        }

        $this->setNow($at->addHours(2));
        $items = WorkItem::where('order_id', $order->id)->get();

        foreach ($items as $item) {
            $values = [];

            foreach ($item->labTest->analytes as $i => $analyte) {
                $pair = isset(self::NUMERIC[$analyte->code])
                    ? [self::NUMERIC[$analyte->code][4], self::NUMERIC[$analyte->code][5]]
                    : self::OTHER_VALUES[$analyte->code];

                $values[$analyte->id] = ['value' => $pair[$abnormal && $i % 2 === 0 ? 1 : 0]];
            }

            $item = $item->fresh();
            app(EnterResults::class)->handle($item, $values, $item->lock_version, submit: true);
        }

        // Yesterday's last order is left waiting for the verifier.
        if ($back === 1 && $slot === 2) {
            return;
        }

        $this->setNow($at->addHours(3));
        Auth::setUser($staff['verifier']);

        foreach ($items as $item) {
            app(VerifyResults::class)->verify($item->fresh());
        }
    }

    private function setNow(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
