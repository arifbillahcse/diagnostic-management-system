<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\ClinicManager;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Message as MachineMessage;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\CommissionPayout;
use Modules\Finance\Models\Expense;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Enums\CashSessionStatus;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Models\Refund;
use Modules\Imaging\Models\Study;
use Modules\Inventory\Models\Item;
use Modules\Laboratory\Models\Transfer;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\People\Models\Staff;
use Tests\TestCase;

final class DemoDataTest extends TestCase
{
    public function test_one_command_fills_every_module_with_sample_data(): void
    {
        $clinic = $this->createClinic('alpha');

        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])
            ->expectsOutputToContain('Where to look')
            ->expectsOutputToContain('bill number:')
            ->assertSuccessful();

        $this->assertNull(auth()->user());
        $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 5, 'The clock was left moved.');

        app(ClinicManager::class)->activate($clinic);

        foreach (['frontdesk', 'laboratory', 'reports', 'finance', 'owner', 'inventory', 'imaging', 'messaging', 'portal', 'people', 'multibranch', 'analyzers'] as $module) {
            $this->assertTrue(app(ModuleState::class)->isEnabled($module), "{$module} is not on.");
        }

        // Front desk: a drawer closed short with a reason, and a refund in each state.
        $closed = CashSession::where('status', CashSessionStatus::Closed->value)->sole();
        $this->assertSame('-50.00', $closed->difference->toDecimal());
        $this->assertNotNull($closed->notes);
        $this->assertSame(['approved', 'pending', 'rejected'], Refund::orderBy('status')->get()->map(fn (Refund $r): string => $r->status->value)->all());
        $this->assertTrue(CashSession::where('status', CashSessionStatus::Open->value)->exists());

        // Branches and collection centres: one box received, one on the way.
        $this->assertSame(2, Branch::count());
        $this->assertEqualsCanonicalizing(['in_transit', 'received'], Transfer::pluck('status')->all());

        // Machines: every kind of message the inbox can show.
        $this->assertSame(2, Machine::count());
        $this->assertEqualsCanonicalizing(['applied', 'failed', 'partial', 'unmatched'], MachineMessage::pluck('status')->unique()->values()->all());

        // Finance: money is never below zero, one expense cancelled, commission part paid.
        foreach (Account::all() as $account) {
            $this->assertFalse(app(Ledger::class)->balance($account)->isNegative(), "{$account->name} is below zero.");
        }
        $this->assertTrue(Expense::whereNotNull('voided_at')->exists());
        $this->assertSame(1, CommissionPayout::count());

        // The other modules.
        $this->assertSame(7, Item::count());
        $this->assertTrue(Study::query()->exists());
        $this->assertGreaterThanOrEqual(4, Staff::count());

        // Messages: sent, failed, and one number that asked to stop.
        $this->assertTrue(Message::where('status', MessageStatus::Failed->value)->exists());
        $this->assertTrue(Message::whereIn('template_key', ['order_placed', 'report_ready', 'due_reminder'])->count() > 10);
        $this->assertSame(1, OptOut::count());

        $this->artisan('frontdesk:check-invoices', ['--clinic' => $clinic->id])->assertSuccessful();
        $this->artisan('finance:sync', ['--clinic' => $clinic->id])->expectsOutputToContain('0 missing')->assertSuccessful();
    }

    public function test_it_refuses_a_second_run_a_missing_clinic_and_a_production_server(): void
    {
        $clinic = $this->createClinic('alpha');

        $this->artisan('clinic:demo-data', ['clinic' => 999, '--force' => true])->assertFailed();

        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])->assertSuccessful();
        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id, '--password' => 'sample-pass-123', '--force' => true])
            ->expectsOutputToContain('already has services or orders')
            ->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('clinic:demo-data', ['clinic' => $clinic->id])
            ->expectsOutputToContain('This server is in production')
            ->assertFailed();
    }

    public function test_a_module_command_says_what_to_run_first_when_the_clinic_is_empty(): void
    {
        $clinic = $this->createClinic('alpha');

        $this->artisan('frontdesk:sample-data', ['clinic' => $clinic->id, '--force' => true])
            ->expectsOutputToContain('Run laboratory:sample-data first')
            ->assertFailed();
        $this->artisan('finance:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertFailed();
        $this->artisan('analyzers:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertFailed();
        $this->assertNull(auth()->user());
    }
}
