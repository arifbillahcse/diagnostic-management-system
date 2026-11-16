<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Export\DataExporter;
use App\Core\Money\Money;
use App\Core\Patients\DuplicateFinder;
use App\Core\Patients\Patient;
use App\Core\Settings\Settings;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Support\Commission;
use Modules\Finance\Support\Ledger;
use Modules\Finance\Support\ProfitReport;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Actions\RecordPayment;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Refund;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Support\DailyFigures;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Filament\Catalog\LabTestResource;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Owner\Support\SalesReports;
use Modules\Reports\Actions\PrintReport;
use Modules\Reports\Filament\ReportResource;

/**
 * Times the queries behind the busiest screens and the main actions,
 * against whatever data the clinic holds. Run it after perf:seed.
 *
 * Each item is run several times and the middle time is reported, with the
 * number of database queries it made.
 */
final class PerfMeasure extends Command
{
    protected $signature = 'perf:measure
        {clinic : Numeric clinic ID}
        {--markdown : Print a Markdown table}
        {--slow=100 : Mark anything slower than this many milliseconds}';

    protected $description = 'Time the busiest screens and actions against a clinic\'s data';

    protected $hidden = true;

    public function handle(ClinicManager $manager): int
    {
        $manager->activate(Clinic::findOrFail((int) $this->argument('clinic')));
        $owner = User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail();
        Auth::setUser($owner);

        $branch = Branch::firstOrFail();
        $daily = app(DailyFigures::class);
        $sales = app(SalesReports::class);
        $today = $daily->today();
        $day = fn (int $ago): string => $today->subDays($ago)->toDateString();

        $somePatient = Patient::query()->where('id', '>', (int) (Patient::max('id') / 2))->firstOrFail();
        $someOrder = Order::query()->where('id', '>', (int) (Order::max('id') / 2))->firstOrFail();
        $releasedOrder = Order::whereIn('id', WorkItem::select('order_id')->where('status', WorkStatus::Released->value))->orderByDesc('id')->firstOrFail();
        $someSample = Sample::query()->where('id', '>', (int) (Sample::max('id') / 2))->firstOrFail();
        $services = Service::where('is_active', true)->limit(3)->pluck('id')->all();

        $page = fn ($query) => [$query->limit(10)->get(), (clone $query)->toBase()->getCountForPagination()];
        $like = fn (string $term): string => $term.'%';
        // Lists shown without a total fetch one extra row to know if there is a next page.
        $simple = fn ($query) => $query->limit(11)->get();

        $reads = [
            'Patient search by start of name' => fn () => Patient::search('Rah')->limit(20)->get(),
            'Patient search by phone' => fn () => Patient::search($somePatient->phone)->limit(20)->get(),
            'Patient search by code' => fn () => Patient::search($somePatient->patient_code)->limit(20)->get(),
            'Duplicate check by phone on registration' => fn () => app(DuplicateFinder::class)->byPhone($somePatient->phone),
            'Patient list, first page' => fn () => $page(Patient::query()->orderByDesc('id')),
            'Order list, first page' => fn () => $page(Order::with(['patient', 'invoice'])->orderByDesc('id')),
            'Order search by order number' => fn () => Order::where('order_no', 'like', $like(substr($someOrder->order_no, 0, 16)))->orderByDesc('id')->limit(10)->get(),
            'Order search by patient name' => fn () => Order::whereIn('patient_id', Patient::search('Rahim U')->select('id'))->orderByDesc('id')->limit(10)->get(),
            'One patient\'s past orders' => fn () => Order::where('patient_id', $somePatient->id)->orderByDesc('ordered_at')->limit(20)->get(),
            'Open one order (items, invoice, payments)' => fn () => Order::with(['items', 'invoice.lines', 'invoice.payments', 'invoice.refunds', 'patient'])->find($someOrder->id),
            'Dues list, first page' => fn () => $page(Invoice::whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])->with(['patient', 'order'])->orderBy('issued_at')),
            'Dashboard: today\'s figures' => fn () => $daily->for($today, $branch->id),
            'Dashboard: this month so far' => fn () => $sales->byDay(...$sales->thisMonth()),
            'Dashboard: 14-day collections chart' => fn () => $sales->byDay($day(13), $day(0)),
            'Dashboard: laboratory status' => fn () => [
                Sample::whereIn('status', [SampleStatus::Pending->value, SampleStatus::Rejected->value])->count(),
                WorkItem::whereIn('status', [WorkStatus::Ready->value, WorkStatus::Amending->value])->count(),
                WorkItem::where('status', WorkStatus::Entered->value)->count(),
                WorkItem::where('status', WorkStatus::Released->value)->where('released_at', '>=', $today->utc())->count(),
            ],
            'Dashboard: money held in accounts' => fn () => app(Ledger::class)->balances(),
            'Menu badges (run on every page)' => fn () => [
                Sample::whereIn('status', [SampleStatus::Pending->value, SampleStatus::Rejected->value])->count(),
                WorkItem::whereIn('status', [WorkStatus::Ready->value, WorkStatus::Entered->value, WorkStatus::Amending->value])->count(),
                Refund::where('status', RefundStatus::Pending->value)->count(),
                LabTestResource::notSetUp()->count(),
            ],
            'Samples list, to collect' => fn () => $page(Sample::whereIn('status', [SampleStatus::Pending->value, SampleStatus::Collected->value, SampleStatus::Rejected->value])->with(['patient', 'sampleType', 'workItems'])->orderByDesc('id')),
            'Scan a sample label' => fn () => Sample::where('accession_no', 'like', $like($someSample->accession_no))->limit(10)->get(),
            'Worklist: to enter' => fn () => $simple(WorkItem::whereIn('status', [WorkStatus::Ready->value, WorkStatus::Amending->value])->with(['patient', 'sample', 'order'])->orderBy('id')),
            'Worklist: released, newest first' => fn () => $simple(WorkItem::where('status', WorkStatus::Released->value)->with(['patient', 'sample', 'order'])->orderByDesc('released_at')),
            'Reports ready to print, first page' => fn () => $simple(ReportResource::getEloquentQuery()->orderByDesc('id')),
            'One patient\'s past results' => fn () => WorkItem::where('patient_id', $somePatient->id)->whereNotNull('released_at')->orderByDesc('released_at')->limit(20)->with('results')->get(),
            'Audit log, first page' => fn () => $simple(AuditLog::query()->orderByDesc('id')),
            'Audit history of one record' => fn () => AuditLog::where('entity_type', 'order')->where('entity_id', $someOrder->id)->orderBy('occurred_at')->get(),
            'Sales report by day, 92 days' => fn () => $sales->byDay($day(91), $day(0)),
            'Sales report by test, 92 days' => fn () => $sales->byTest($day(91), $day(0)),
            'Sales report by doctor, 92 days' => fn () => $sales->byDoctor($day(91), $day(0)),
            'Sales report by cashier, 92 days' => fn () => $sales->byCashier($day(91), $day(0)),
            'Profit report, one year' => fn () => app(ProfitReport::class)->for($day(364), $day(0)),
            'Commission list (30 doctors)' => fn () => ReferralDoctor::orderBy('name')->limit(10)->get()->map(fn ($d) => [app(Commission::class)->earned($d), app(Commission::class)->paid($d)]),
            'Data export page (row counts)' => fn () => app(DataExporter::class)->approximateCounts(),
            'Lab report as PDF' => fn () => app(PrintReport::class)->handle($releasedOrder),
        ];

        // Actions write real rows, so each run uses a fresh order.
        $newOrder = fn (): Order => app(PlaceOrder::class)->handle($somePatient, $branch, $services);
        $state = ['order' => null];

        $writes = [
            'Create an order with 3 tests (and its lab work)' => function () use ($newOrder, &$state): void {
                $state['order'] = $newOrder();
            },
            'Take a payment' => function () use (&$state): void {
                app(RecordPayment::class)->handle($state['order']->invoice->fresh(), Money::fromDecimal('100'), PaymentMethod::Bkash);
            },
            'Collect and receive a sample' => function () use (&$state): void {
                foreach (Sample::where('order_id', $state['order']->id)->where('status', SampleStatus::Pending->value)->get() as $sample) {
                    app(HandleSample::class)->collect($sample, true);
                }
            },
            'Enter and submit results for one test' => function () use (&$state): void {
                $item = WorkItem::where('order_id', $state['order']->id)->where('status', WorkStatus::Ready->value)->firstOrFail();
                $values = $item->labTest->analytes->mapWithKeys(fn ($a) => [$a->id => ['value' => '12.5']])->all();
                $state['item'] = app(EnterResults::class)->handle($item, $values, $item->lock_version, true);
            },
            'Verify and release that test' => function () use (&$state): void {
                app(Settings::class)->set('laboratory.verifier_must_differ', false);
                app(VerifyResults::class)->verify($state['item']);
            },
        ];

        $rows = [];

        foreach ($reads as $name => $work) {
            $rows[] = $this->time($name, $work, 5);
        }

        // One full pass of the write flow per round, timed step by step.
        $samples = [];
        for ($round = 0; $round < 3; $round++) {
            foreach ($writes as $name => $work) {
                $samples[$name][] = $this->once($work);
            }
        }
        foreach ($samples as $name => $runs) {
            $rows[] = $this->summarise($name, $runs);
        }

        $slow = (float) $this->option('slow');
        $table = array_map(fn (array $r): array => [$r['name'], number_format($r['ms'], 1), (string) $r['queries'], $r['ms'] > $slow ? 'slow' : 'ok'], $rows);

        if ($this->option('markdown')) {
            $this->line('| What | Time (ms) | Queries | |');
            $this->line('|---|---:|---:|---|');
            foreach ($table as $row) {
                $this->line('| '.implode(' | ', $row).' |');
            }
        } else {
            $this->table(['What', 'Time (ms)', 'Queries', ''], $table);
        }

        $sizes = DB::connection('clinic')->select('SELECT table_name AS name, table_rows AS approx_rows, ROUND((data_length + index_length) / 1048576) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 8');
        $this->newLine();
        $this->table(['Largest tables', 'Rows (approx.)', 'Size (MB)'], array_map(fn ($s): array => [$s->name, number_format((int) $s->approx_rows), $s->mb], $sizes));

        $slowCount = count(array_filter($rows, fn (array $r): bool => $r['ms'] > $slow));
        $this->line($slowCount === 0 ? "Nothing slower than {$slow} ms." : "{$slowCount} item(s) slower than {$slow} ms.");

        return self::SUCCESS;
    }

    /** @return array{name: string, ms: float, queries: int} */
    private function time(string $name, Closure $work, int $runs): array
    {
        $samples = [];

        for ($i = 0; $i < $runs; $i++) {
            $samples[] = $this->once($work);
        }

        return $this->summarise($name, $samples);
    }

    /** @return array{ms: float, queries: int} */
    private function once(Closure $work): array
    {
        $queries = 0;
        DB::connection('clinic')->flushQueryLog();
        DB::connection('clinic')->enableQueryLog();
        DB::connection('control')->enableQueryLog();
        DB::connection('control')->flushQueryLog();

        $start = hrtime(true);
        $work();
        $ms = (hrtime(true) - $start) / 1_000_000;

        $queries = count(DB::connection('clinic')->getQueryLog()) + count(DB::connection('control')->getQueryLog());
        DB::connection('clinic')->disableQueryLog();
        DB::connection('control')->disableQueryLog();

        return ['ms' => $ms, 'queries' => $queries];
    }

    /**
     * @param  list<array{ms: float, queries: int}>  $samples
     * @return array{name: string, ms: float, queries: int}
     */
    private function summarise(string $name, array $samples): array
    {
        usort($samples, fn (array $a, array $b): int => $a['ms'] <=> $b['ms']);
        $middle = $samples[intdiv(count($samples), 2)];

        return ['name' => $name, 'ms' => $middle['ms'], 'queries' => $middle['queries']];
    }
}
