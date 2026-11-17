<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Branches\Branch;
use App\Core\Numbering\Actions\ChangeNumberFormats;
use App\Core\Numbering\CounterService;
use App\Core\Numbering\NumberGenerator;
use App\Core\Settings\Settings;
use App\Filament\Pages\ManageSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
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
            '2611-00001',
            '2611-00002',
            'P-26-000001',
            '2611150001',
            '2612-00001',
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

        $this->assertSame('2612-00001', $number);
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
        app(Settings::class)->set('numbering.invoice', 'INV-{branch}-{yymm}-{00001}');

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

    private function issue(string $type, ?Branch $branch = null, string $at = '2026-11-17 10:00'): string
    {
        return DB::connection('clinic')->transaction(
            fn () => app(NumberGenerator::class)->generate($type, $branch, CarbonImmutable::parse($at, 'Asia/Dhaka')),
        );
    }

    public function test_dropping_the_prefix_keeps_the_count_going(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $branch = Branch::firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Dhaka'));
        app(Settings::class)->set('numbering.invoice', 'INV-{branch}-{yymm}-{00001}'); // a clinic still on the long format

        $this->assertSame('INV-MAIN-2611-00001', $this->issue('invoice', $branch));
        $this->assertSame('INV-MAIN-2611-00002', $this->issue('invoice', $branch));

        $changed = app(ChangeNumberFormats::class)->handle(['invoice' => '{yymm}-{00001}']);

        $this->assertSame(['invoice'], $changed);
        $this->assertSame('2611-00003', $this->issue('invoice', $branch));
        $this->assertSame('2611-00004', $this->issue('invoice', null), 'one counter for the whole clinic now');
        $this->assertSame('2612-00001', $this->issue('invoice', null, '2026-12-01 10:00'), 'a new month still starts again');

        // Going back resumes the old count for that month, not 1.
        app(ChangeNumberFormats::class)->handle(['invoice' => 'INV-{branch}-{yymm}-{00001}']);
        $this->assertSame('INV-MAIN-2611-00005', $this->issue('invoice', $branch));

        $audit = AuditLog::where('action', 'numbering.changed')->orderBy('id')->first();
        $this->assertSame('INV-{branch}-{yymm}-{00001}', $audit->before['numbering.invoice']);
        $this->assertSame('{yymm}-{00001}', $audit->after['numbering.invoice']);
    }

    public function test_a_format_that_restarts_at_other_moments_does_not_inherit_the_count(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $branch = Branch::firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Dhaka'));

        $this->issue('invoice', $branch);
        $this->issue('invoice', $branch);
        app(ChangeNumberFormats::class)->handle(['invoice' => '{yymmdd}{0001}']);

        $this->assertSame('2611170001', $this->issue('invoice', null), 'daily numbers start at 1 each day');
    }

    public function test_a_bad_format_is_refused_and_nothing_changes(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $action = app(ChangeNumberFormats::class);

        foreach ([['invoice' => 'NOCOUNTER'], ['invoice' => '{00001}-{0001}'], ['invoice' => "BILL'; DROP--{00001}"], ['unknown' => '{00001}']] as $bad) {
            try {
                $action->handle(['receipt' => 'R-{00001}'] + $bad);
                $this->fail('A bad format was accepted: '.json_encode($bad));
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertNull(app(Settings::class)->get('numbering.receipt'), 'the good format in the same request was not saved either');
        $this->assertSame([], $action->handle(['invoice' => '{yymm}-{00001}']), 'the same format is no change');
    }

    public function test_only_staff_who_manage_settings_may_change_formats(): void
    {
        $this->useClinic();
        $this->actingAs($this->userWithRoles('receptionist'));

        $this->expectException(AuthorizationException::class);
        app(ChangeNumberFormats::class)->handle(['invoice' => '{yymm}-{00001}']);
    }

    public function test_the_settings_screen_shows_the_formats_and_saves_a_new_one(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ManageSettings::class)
            ->assertFormSet(['num_invoice' => '{yymm}-{00001}', 'num_patient' => 'P-{yy}-{000001}'])
            ->fillForm(['name' => 'Alpha Diagnostics', 'num_invoice' => 'BILL-{yymm}-{00001}'])
            ->call('save')
            ->assertNotified('Settings saved');

        $this->assertSame('BILL-{yymm}-{00001}', app(Settings::class)->get('numbering.invoice'));

        Livewire::test(ManageSettings::class)
            ->fillForm(['name' => 'Alpha Diagnostics', 'num_invoice' => 'NOCOUNTER'])
            ->call('save')
            ->assertNotified('Number formats not saved');

        $this->assertSame('BILL-{yymm}-{00001}', app(Settings::class)->get('numbering.invoice'));
    }

    public function test_a_clinic_that_already_issued_numbers_keeps_its_format_after_the_defaults_changed(): void
    {
        $this->useClinic();
        $db = DB::connection('clinic');
        $db->table('settings')->where('group', 'numbering')->delete();

        // This clinic has issued invoices and receipts, but no refunds yet.
        foreach (['invoice', 'receipt'] as $type) {
            $db->table('counters')->insert(['counter_key' => $type, 'branch_id' => 1, 'period' => '2611', 'next_value' => 35, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = base_path('database/migrations/clinic/2026_11_22_000001_keep_number_formats_of_existing_clinics.php');
        (require $migration)->up();
        app(Settings::class)->flush();

        $this->assertSame('INV-{branch}-{yymm}-{00001}', app(NumberGenerator::class)->format('invoice')->pattern);
        $this->assertSame('RCP-{branch}-{yymm}-{00001}', app(NumberGenerator::class)->format('receipt')->pattern);
        $this->assertSame('RF-{yymm}-{00001}', app(NumberGenerator::class)->format('refund')->pattern, 'never used, so it takes the new short default');

        // A format the clinic chose itself is left alone.
        $db->table('settings')->where('group', 'numbering')->where('key', 'invoice')->update(['value' => json_encode('{yymm}-{00001}')]);
        (require $migration)->up();
        app(Settings::class)->flush();
        $this->assertSame('{yymm}-{00001}', app(NumberGenerator::class)->format('invoice')->pattern);
    }

    public function test_a_new_clinic_starts_with_the_short_formats(): void
    {
        $this->useClinic();
        $branch = Branch::firstOrFail();
        $at = CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Dhaka');

        $numbers = DB::connection('clinic')->transaction(fn () => array_map(
            fn (string $type): string => app(NumberGenerator::class)->generate($type, $branch, $at),
            ['order', 'invoice', 'receipt', 'refund'],
        ));

        $this->assertSame(['ORD-2611-00001', '2611-00001', 'MR-2611-00001', 'RF-2611-00001'], $numbers);
    }
}
