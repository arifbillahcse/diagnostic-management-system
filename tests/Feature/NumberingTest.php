<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Branches\Branch;
use App\Core\Numbering\CounterService;
use App\Core\Numbering\NumberGenerator;
use App\Core\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class NumberingTest extends TestCase
{
    private function next(string $key, int $branch = 0, string $period = ''): int
    {
        return DB::connection('clinic')->transaction(fn () => app(CounterService::class)->next($key, $branch, $period));
    }

    public function test_a_counter_starts_at_one_and_counts_up(): void
    {
        $this->useClinic();

        $this->assertSame([1, 2, 3, 4], [$this->next('invoice'), $this->next('invoice'), $this->next('invoice'), $this->next('invoice')]);
    }

    public function test_counters_are_separate_per_key_branch_and_period(): void
    {
        $this->useClinic();

        $this->assertSame(1, $this->next('invoice', 1, '2611'));
        $this->assertSame(2, $this->next('invoice', 1, '2611'));
        $this->assertSame(1, $this->next('invoice', 2, '2611'), 'another branch');
        $this->assertSame(1, $this->next('invoice', 1, '2612'), 'another month');
        $this->assertSame(1, $this->next('receipt', 1, '2611'), 'another counter');
        $this->assertSame(3, $this->next('invoice', 1, '2611'));
    }

    public function test_a_number_must_be_issued_inside_a_transaction(): void
    {
        $this->useClinic();

        $this->expectException(LogicException::class);
        app(CounterService::class)->next('invoice');
    }

    public function test_a_failed_save_gives_its_number_back(): void
    {
        $this->useClinic();
        $this->assertSame(1, $this->next('invoice'));

        try {
            DB::connection('clinic')->transaction(function (): void {
                $this->assertSame(2, app(CounterService::class)->next('invoice'));

                throw new RuntimeException('The invoice could not be saved.');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(2, $this->next('invoice'), 'no gap after the failed save');
    }

    public function test_business_numbers_use_the_default_formats(): void
    {
        $this->useClinic();
        $branch = Branch::first();
        $generator = app(NumberGenerator::class);
        $at = CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Dhaka');

        $numbers = DB::connection('clinic')->transaction(fn () => [
            $generator->generate('invoice', $branch, $at),
            $generator->generate('invoice', $branch, $at),
            $generator->generate('patient', null, $at),
            $generator->generate('accession', null, $at),
            $generator->generate('invoice', $branch, $at->addMonth()),
        ]);

        $this->assertSame([
            'INV-MAIN-2611-00001',
            'INV-MAIN-2611-00002',
            'P-26-000001',
            '2611150001',
            'INV-MAIN-2612-00001',
        ], $numbers);
    }

    public function test_the_period_follows_the_clinics_local_date_not_utc(): void
    {
        $this->useClinic();
        $branch = Branch::first();

        // 20:30 UTC on 30 November is already 02:30 on 1 December in Dhaka.
        $number = DB::connection('clinic')->transaction(
            fn () => app(NumberGenerator::class)->generate('invoice', $branch, CarbonImmutable::parse('2026-11-30 20:30', 'UTC')),
        );

        $this->assertSame('INV-MAIN-2612-00001', $number);
    }

    public function test_a_clinic_can_set_its_own_format(): void
    {
        $this->useClinic();
        app(Settings::class)->set('numbering.invoice', 'BILL/{yyyy}/{0001}');

        $number = DB::connection('clinic')->transaction(
            fn () => app(NumberGenerator::class)->generate('invoice', null, CarbonImmutable::parse('2026-03-01', 'Asia/Dhaka')),
        );

        $this->assertSame('BILL/2026/0001', $number);
    }

    public function test_a_branch_format_without_a_branch_is_refused(): void
    {
        $this->useClinic();

        $this->expectException(InvalidArgumentException::class);
        DB::connection('clinic')->transaction(fn () => app(NumberGenerator::class)->generate('invoice'));
    }

    public function test_simultaneous_requests_never_share_or_skip_a_number(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Row locking is only meaningful on MySQL. Run the suite with DB_DRIVER=mysql.');
        }

        $clinic = $this->useClinic();
        $workers = 6;
        $each = 25;

        // Start every worker before waiting for any, so they truly overlap.
        // This includes the very first number, when the counter row does
        // not exist yet.
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[] = $process = new Process([PHP_BINARY, base_path('tests/Support/counter_worker.php'), (string) $clinic->id, (string) $each]);
            $process->setTimeout(120);
            $process->start();
        }

        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $numbers = [...$numbers, ...array_filter(explode(PHP_EOL, trim($process->getOutput())))];
        }

        $total = $workers * $each;
        $this->assertCount($total, $numbers);
        $this->assertCount($total, array_unique($numbers), 'every number is different');

        $sequences = array_map(fn (string $n): int => (int) substr($n, -5), $numbers);
        sort($sequences);
        $this->assertSame(range(1, $total), $sequences, 'no number was skipped');
    }
}
