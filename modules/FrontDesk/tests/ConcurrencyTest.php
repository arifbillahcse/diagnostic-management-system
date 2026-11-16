<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Tenancy\ClinicContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Models\Payment;
use Symfony\Component\Process\Process;

/**
 * Checks that need real MySQL: row locks and check constraints.
 */
final class ConcurrencyTest extends FrontDeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->onMySql()) {
            $this->markTestSkipped('Needs MySQL. Run the suite with DB_DRIVER=mysql.');
        }
    }

    public function test_simultaneous_payments_can_never_exceed_the_amount_due(): void
    {
        $order = $this->order(['1000']);
        $invoice = $this->invoice($order);
        $clinicId = app(ClinicContext::class)->id();

        // Eight people try to pay 300 at the same moment. Only three fit.
        $processes = [];
        for ($i = 0; $i < 8; $i++) {
            $processes[] = $process = new Process([PHP_BINARY, __DIR__.'/payment_worker.php', (string) $clinicId, (string) $invoice->id, '300']);
            $process->setTimeout(120);
            $process->start();
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $results[] = str_starts_with($process->getOutput(), 'paid') ? 'paid' : 'refused';
        }

        $this->assertEquals(['paid' => 3, 'refused' => 5], array_count_values($results));
        $this->assertSame(3, Payment::count());
        $this->assertInvoice($order, '1000.00', '900.00', '0.00', '100.00', 'partially_paid');

        $receipts = Payment::pluck('receipt_no')->all();
        $this->assertCount(3, array_unique($receipts));
    }

    public function test_the_database_itself_refuses_impossible_amounts(): void
    {
        $order = $this->order(['1000']);
        $invoice = $this->invoice($order);
        $db = DB::connection('clinic');

        $attempts = [
            'a payment of zero' => fn () => $db->table('fd_payments')->insert([
                'public_id' => str_repeat('A', 26), 'receipt_no' => 'X-1', 'invoice_id' => $invoice->id, 'branch_id' => $this->branch->id,
                'amount' => 0, 'method' => 'cash', 'received_at' => now(), 'created_at' => now(),
            ]),
            'refunded more than paid' => fn () => $db->table('fd_invoices')->where('id', $invoice->id)->update(['paid_total' => 100, 'refunded_total' => 200]),
            'a negative price' => fn () => $db->table('fd_services')->update(['price' => -1]),
            'a discount above the price' => fn () => $db->table('fd_order_items')->update(['discount_amount' => 5000]),
        ];

        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("MySQL should have refused {$case}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('Check constraint', $e->getMessage(), $case);
            }
        }
    }
}
