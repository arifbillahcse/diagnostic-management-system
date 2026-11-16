<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleManager;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\SampleType;

/**
 * Fills one clinic with years of made-up but internally consistent data,
 * so query speed can be measured at a realistic size.
 *
 * Rows are written in bulk straight to the database. That skips the
 * application's rules on purpose (it is hundreds of times faster), so this
 * must only ever be pointed at a throwaway clinic.
 */
final class PerfSeed extends Command
{
    protected $signature = 'perf:seed
        {clinic : Numeric ID of a throwaway clinic}
        {--days=730 : How many days of history}
        {--orders=500 : Orders per day}
        {--force : Allow in production (never do this on a real clinic)}';

    protected $description = 'Load a throwaway clinic with years of simulated data for performance testing';

    protected $hidden = true;

    /** @var array<string, int> next primary key per table */
    private array $ids = [];

    /** @var array<string, list<array<string, mixed>>> rows waiting to be written */
    private array $buffer = [];

    public function handle(ClinicManager $manager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $clinic = Clinic::findOrFail((int) $this->argument('clinic'));
        $manager->activate($clinic);
        $db = DB::connection('clinic');

        if ($db->table('fd_orders')->count() > 1000) {
            $this->error('This clinic already holds a lot of orders. Use a fresh throwaway clinic.');

            return self::FAILURE;
        }

        foreach (['finance', 'owner'] as $module) {
            app(ModuleManager::class)->enable($module);
        }

        $started = microtime(true);
        $catalog = $this->catalog();
        $branch = Branch::firstOrFail();
        $days = (int) $this->option('days');
        $perDay = (int) $this->option('orders');
        $timezone = $clinic->timezone;

        foreach ([
            'patients', 'fd_orders', 'fd_order_items', 'fd_invoices', 'fd_invoice_lines', 'fd_payments', 'lab_samples',
            'lab_work_items', 'lab_results', 'lab_result_versions', 'audit_logs', 'fin_account_entries', 'fin_commission_entries',
        ] as $table) {
            $this->ids[$table] = (int) $db->table($table)->max('id') + 1;
        }

        $accountIds = $db->table('fin_accounts')->pluck('id', 'type')->all();
        foreach (['cash', 'bkash'] as $type) {
            $accountIds[$type] ??= $db->table('fin_accounts')->insertGetId([
                'public_id' => $this->publicId('A', count($accountIds) + 1), 'name' => ucfirst($type), 'type' => $type,
                'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $firstNames = ['Rahim', 'Karim', 'Salma', 'Nasrin', 'Rafi', 'Tanvir', 'Shila', 'Jamal', 'Rina', 'Faruk', 'Ayesha', 'Imran', 'Sumaiya', 'Habib', 'Mitu', 'Sohel', 'Laila', 'Babul', 'Nadia', 'Zubair'];
        $lastNames = ['Uddin', 'Begum', 'Ahmed', 'Hossain', 'Akter', 'Islam', 'Khatun', 'Rahman', 'Sultana', 'Miah', 'Chowdhury', 'Sarkar', 'Khan', 'Biswas', 'Molla'];

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $patientCount = (int) $db->table('patients')->count();
        $monthCounters = [];

        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        $bar = $this->output->createProgressBar($days);

        for ($d = $days - 1; $d >= 0; $d--) {
            $day = $today->subDays($d);
            $month = $day->format('ym');
            $monthCounters[$month] ??= 0;
            $recent = $d <= 1; // the last two days still have work in progress

            for ($n = 0; $n < $perDay; $n++) {
                $at = $day->addSeconds(random_int(8 * 3600, 20 * 3600))->utc()->format('Y-m-d H:i:s');

                // Four in ten visits are by a patient who has been before.
                if ($patientCount > 50 && random_int(1, 10) <= 4) {
                    $patientId = random_int(1, $patientCount);
                } else {
                    $patientId = $this->ids['patients']++;
                    $patientCount = $patientId;
                    $phone = '017'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
                    $this->row('patients', [
                        'id' => $patientId, 'public_id' => $this->publicId('P', $patientId),
                        'patient_code' => 'P-'.$day->format('y').'-'.str_pad((string) $patientId, 6, '0', STR_PAD_LEFT),
                        'name' => $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)],
                        'sex' => random_int(0, 1) ? 'male' : 'female',
                        'date_of_birth' => $day->subDays(random_int(200, 30000))->toDateString(),
                        'dob_is_estimated' => 1, 'phone' => $phone, 'phone_normalized' => '88'.$phone,
                        'registered_branch_id' => $branch->id, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                }

                $sequence = ++$monthCounters[$month];
                $number = $branch->code.'-'.$month.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
                $orderId = $this->ids['fd_orders']++;
                $invoiceId = $this->ids['fd_invoices']++;
                $doctorId = random_int(0, 1) ? $catalog['doctors'][array_rand($catalog['doctors'])] : null;
                $tests = (array) array_rand($catalog['tests'], random_int(1, 3));

                // How far this order has got. Old orders are finished.
                $stage = $recent ? random_int(0, 3) : 3; // 0 awaiting sample, 1 ready, 2 entered, 3 released
                $total = 0;
                $sampleByType = [];

                foreach ($tests as $index) {
                    $test = $catalog['tests'][$index];
                    $itemId = $this->ids['fd_order_items']++;
                    $total += $test['price'];

                    $this->row('fd_order_items', [
                        'id' => $itemId, 'order_id' => $orderId, 'service_id' => $test['service_id'], 'service_name' => $test['name'],
                        'service_type' => 'lab_test', 'unit_price' => $test['price'], 'discount_amount' => 0, 'net_amount' => $test['price'],
                        'status' => 'active', 'created_at' => $at, 'updated_at' => $at,
                    ]);
                    $this->row('fd_invoice_lines', [
                        'id' => $this->ids['fd_invoice_lines']++, 'invoice_id' => $invoiceId, 'order_item_id' => $itemId,
                        'kind' => 'charge', 'description' => $test['name'], 'amount' => $test['price'], 'created_at' => $at,
                    ]);

                    if (! isset($sampleByType[$test['sample_type_id']])) {
                        $sampleId = $this->ids['lab_samples']++;
                        $sampleByType[$test['sample_type_id']] = $sampleId;
                        $this->row('lab_samples', [
                            'id' => $sampleId, 'public_id' => $this->publicId('S', $sampleId),
                            'accession_no' => $day->format('ymd').str_pad((string) ($n * 2 + count($sampleByType)), 4, '0', STR_PAD_LEFT),
                            'order_id' => $orderId, 'patient_id' => $patientId, 'sample_type_id' => $test['sample_type_id'], 'branch_id' => $branch->id,
                            'status' => ['pending', 'received', 'in_process', 'completed'][$stage],
                            'collected_at' => $stage > 0 ? $at : null, 'received_at' => $stage > 0 ? $at : null,
                            'created_at' => $at, 'updated_at' => $at,
                        ]);
                    }

                    $workId = $this->ids['lab_work_items']++;
                    $this->row('lab_work_items', [
                        'id' => $workId, 'public_id' => $this->publicId('W', $workId), 'order_item_id' => $itemId, 'order_id' => $orderId,
                        'lab_test_id' => $test['lab_test_id'], 'sample_id' => $sampleByType[$test['sample_type_id']], 'patient_id' => $patientId,
                        'branch_id' => $branch->id, 'test_name' => $test['name'], 'department' => $test['department'],
                        'status' => ['awaiting_sample', 'ready', 'entered', 'released'][$stage],
                        'entered_by' => $stage >= 2 ? $catalog['users'][0] : null, 'entered_at' => $stage >= 2 ? $at : null,
                        'verified_by' => $stage === 3 ? $catalog['users'][1] : null, 'verified_at' => $stage === 3 ? $at : null,
                        'released_at' => $stage === 3 ? $at : null, 'version' => $stage === 3 ? 1 : 0, 'lock_version' => $stage,
                        'created_at' => $at, 'updated_at' => $at,
                    ]);

                    if ($stage >= 2) {
                        $snapshot = [];

                        foreach ($test['analytes'] as $order => $analyte) {
                            $value = random_int(30, 200) / 10;
                            $flag = $value < 5 ? 'low' : ($value > 17 ? 'high' : 'normal');
                            $this->row('lab_results', [
                                'id' => $this->ids['lab_results']++, 'work_item_id' => $workId, 'analyte_id' => $analyte['id'],
                                'analyte_name' => $analyte['name'], 'result_type' => 'numeric', 'sort_order' => $order,
                                'value_numeric' => $value, 'value_text' => number_format($value, 1), 'unit' => 'g/dL',
                                'ref_low' => 5, 'ref_high' => 17, 'ref_text' => '5.0 - 17.0', 'flag' => $flag,
                                'created_at' => $at, 'updated_at' => $at,
                            ]);
                            $snapshot[] = ['analyte' => $analyte['name'], 'value' => number_format($value, 1), 'flag' => $flag];
                        }

                        if ($stage === 3) {
                            $this->row('lab_result_versions', [
                                'id' => $this->ids['lab_result_versions']++, 'work_item_id' => $workId, 'version' => 1,
                                'snapshot' => json_encode($snapshot), 'entered_by' => $catalog['users'][0], 'verified_by' => $catalog['users'][1], 'verified_at' => $at,
                            ]);
                        }
                    }

                    if ($doctorId !== null && ! $recent) {
                        $this->row('fin_commission_entries', [
                            'id' => $this->ids['fin_commission_entries']++, 'referral_doctor_id' => $doctorId, 'order_item_id' => $itemId,
                            'invoice_id' => $invoiceId, 'amount' => round($test['price'] * 0.10, 2), 'reason' => 'Invoice paid', 'created_at' => $at,
                        ]);
                    }
                }

                // Most invoices are paid in full; some of the newest are not.
                $roll = random_int(1, 100);
                $paid = $recent && $roll > 85 ? 0 : ($recent && $roll > 70 ? intdiv($total, 2) : ($roll > 98 ? intdiv($total, 2) : $total));
                $status = $paid === 0 ? 'issued' : ($paid < $total ? 'partially_paid' : 'paid');

                $this->row('fd_orders', [
                    'id' => $orderId, 'public_id' => $this->publicId('O', $orderId), 'order_no' => 'ORD-'.$number, 'patient_id' => $patientId,
                    'referral_doctor_id' => $doctorId, 'branch_id' => $branch->id,
                    'status' => $stage === 3 ? 'completed' : ($stage >= 2 ? 'in_progress' : 'confirmed'),
                    'ordered_at' => $at, 'created_by' => $catalog['users'][2], 'created_at' => $at, 'updated_at' => $at,
                ]);
                $this->row('fd_invoices', [
                    'id' => $invoiceId, 'public_id' => $this->publicId('I', $invoiceId), 'invoice_no' => 'INV-'.$number, 'order_id' => $orderId,
                    'patient_id' => $patientId, 'branch_id' => $branch->id, 'subtotal' => $total, 'discount_total' => 0, 'adjustment_total' => 0,
                    'total' => $total, 'paid_total' => $paid, 'refunded_total' => 0, 'due_amount' => $total - $paid, 'status' => $status,
                    'issued_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                ]);

                if ($paid > 0) {
                    $paymentId = $this->ids['fd_payments']++;
                    $method = random_int(0, 2) ? 'cash' : 'bkash';
                    $this->row('fd_payments', [
                        'id' => $paymentId, 'public_id' => $this->publicId('Y', $paymentId), 'receipt_no' => 'RCP-'.$number, 'invoice_id' => $invoiceId,
                        'branch_id' => $branch->id, 'amount' => $paid, 'method' => $method, 'received_by' => $catalog['users'][2],
                        'received_at' => $at, 'created_at' => $at,
                    ]);
                    $this->row('fin_account_entries', [
                        'id' => $this->ids['fin_account_entries']++, 'account_id' => $accountIds[$method], 'amount' => $paid, 'occurred_at' => $at,
                        'source_type' => 'payment', 'source_id' => $paymentId, 'description' => 'Receipt RCP-'.$number, 'created_at' => $at,
                    ]);
                }

                foreach (['order.placed' => 'order', 'payment.recorded' => 'payment', 'results.verified' => 'work_item'] as $action => $entity) {
                    $this->row('audit_logs', [
                        'id' => $this->ids['audit_logs']++, 'occurred_at' => $at, 'actor_type' => 'user', 'actor_id' => $catalog['users'][2],
                        'actor_name' => 'Simulated User', 'action' => $action, 'entity_type' => $entity, 'entity_id' => $orderId,
                        'branch_id' => $branch->id, 'after' => '{"simulated":true}',
                    ]);
                }
            }

            $this->flush($db);
            $bar->advance();
        }

        $this->flush($db, true);
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        $bar->finish();
        $this->newLine(2);

        // Leave the counters where real use would have left them.
        foreach ($monthCounters as $month => $last) {
            foreach (['order', 'invoice', 'receipt'] as $key) {
                $db->table('counters')->upsert(
                    [['counter_key' => $key, 'branch_id' => $branch->id, 'period' => $month, 'next_value' => $last + 1, 'created_at' => now(), 'updated_at' => now()]],
                    ['counter_key', 'branch_id', 'period'],
                    ['next_value'],
                );
            }
        }
        $db->table('counters')->upsert(
            [['counter_key' => 'patient', 'branch_id' => 0, 'period' => $today->format('y'), 'next_value' => $this->ids['patients'], 'created_at' => now(), 'updated_at' => now()]],
            ['counter_key', 'branch_id', 'period'],
            ['next_value'],
        );
        $db->table('counters')->where('counter_key', 'accession')->delete();
        $db->table('counters')->insert(['counter_key' => 'accession', 'branch_id' => 0, 'period' => $today->format('ymd'), 'next_value' => $perDay * 2 + 10, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['patients', 'fd_orders', 'fd_order_items', 'fd_invoices', 'fd_payments', 'lab_samples', 'lab_work_items', 'lab_results', 'audit_logs', 'fin_account_entries'] as $table) {
            $db->statement("ANALYZE TABLE `{$table}`");
        }

        $rows = [];
        foreach (array_keys($this->ids) as $table) {
            $rows[] = [$table, number_format($db->table($table)->count())];
        }
        $this->table(['Table', 'Rows'], $rows);
        $this->info('Done in '.round(microtime(true) - $started).' seconds.');

        return self::SUCCESS;
    }

    /**
     * A catalog of 40 lab tests with 1 to 10 analytes each, 30 doctors, and
     * three staff. Small, so it is made through the models.
     *
     * @return array{tests: list<array<string, mixed>>, doctors: list<int>, users: list<int>}
     */
    private function catalog(): array
    {
        $edta = SampleType::firstOrCreate(['name' => 'Blood (EDTA)'], ['label_copies' => 1]);
        $serum = SampleType::firstOrCreate(['name' => 'Serum'], ['label_copies' => 1]);
        $tests = [];

        for ($t = 1; $t <= 40; $t++) {
            $name = 'Perf test '.str_pad((string) $t, 2, '0', STR_PAD_LEFT);
            $price = [150, 200, 300, 400, 600, 800, 1200, 2500][$t % 8];
            $service = Service::firstOrCreate(['code' => 'PF'.$t], ['name' => $name, 'type' => 'lab_test', 'price' => (string) $price]);
            $type = $t % 3 ? $edta : $serum;
            $labTest = LabTest::firstOrCreate(['service_id' => $service->id], ['sample_type_id' => $type->id, 'department' => $t % 2 ? 'Haematology' : 'Biochemistry']);
            $analytes = [];

            // An average of five values per test.
            for ($a = 1; $a <= [1, 2, 3, 5, 5, 6, 8, 10][$t % 8]; $a++) {
                $analyte = Analyte::firstOrCreate(['code' => "PF{$t}A{$a}"], ['name' => "Analyte {$t}.{$a}", 'result_type' => 'numeric', 'unit' => 'g/dL', 'decimals' => 1]);
                $labTest->analyteLinks()->firstOrCreate(['analyte_id' => $analyte->id], ['sort_order' => $a]);
                $analytes[] = ['id' => $analyte->id, 'name' => $analyte->name];
            }

            $tests[] = ['service_id' => $service->id, 'lab_test_id' => $labTest->id, 'name' => $name, 'price' => $price, 'sample_type_id' => $type->id, 'department' => $labTest->department, 'analytes' => $analytes];
        }

        $doctors = [];
        for ($i = 1; $i <= 30; $i++) {
            $doctors[] = ReferralDoctor::firstOrCreate(['name' => "Dr. Perf {$i}"])->id;
        }

        $users = [];
        foreach (['lab_technician' => 'perf-tech', 'verifier' => 'perf-verifier', 'receptionist' => 'perf-reception'] as $role => $slug) {
            $user = User::firstOrCreate(['email' => "{$slug}@example.test"], ['name' => ucfirst($slug), 'password' => 'not-a-real-password-'.random_int(1, PHP_INT_MAX)]);
            DB::connection('clinic')->table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->value('id'), 'branch_id' => null]);
            $users[] = $user->id;
        }

        return ['tests' => $tests, 'doctors' => $doctors, 'users' => $users];
    }

    /** @param array<string, mixed> $row */
    private function row(string $table, array $row): void
    {
        $this->buffer[$table][] = $row;
    }

    private function flush($db, bool $everything = false): void
    {
        foreach ($this->buffer as $table => $rows) {
            if (! $everything && count($rows) < 2000) {
                continue;
            }

            foreach (array_chunk($rows, 2000) as $chunk) {
                $db->table($table)->insert($chunk);
            }

            $this->buffer[$table] = [];
        }
    }

    /** A unique 26-character public ID. Not random: fine for throwaway data. */
    private function publicId(string $prefix, int $id): string
    {
        return $prefix.str_pad(strtoupper(base_convert((string) $id, 10, 36)), 25, '0', STR_PAD_LEFT);
    }
}
