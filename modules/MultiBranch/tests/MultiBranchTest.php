<?php

declare(strict_types=1);

namespace Modules\MultiBranch\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Branches\CurrentBranch;
use App\Core\Documents\Letterhead;
use App\Core\Modules\ModuleManager;
use Livewire\Livewire;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Filament\Orders\ListOrders;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Owner\Filament\SalesReportsPage;
use Modules\Owner\Support\SalesReports;

class MultiBranchTest extends FrontDeskTestCase
{
    private const BASE = 'http://alpha.test';

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = Branch::create(['code' => 'NTH', 'name' => 'North branch', 'address' => 'Sonadanga, Khulna', 'phone' => '01700111222']);
        $this->south = Branch::create(['code' => 'STH', 'name' => 'South branch']);
    }

    private function orderIn(Branch $branch): Order
    {
        return app(PlaceOrder::class)->handle($this->patient(), $branch, [$this->service('CBC '.$branch->code, '400')->id]);
    }

    /** A receptionist in the given branches only. */
    private function receptionistIn(Branch ...$branches): User
    {
        $user = $this->userWithRoles();
        $role = Role::where('key', 'receptionist')->firstOrFail();
        foreach ($branches as $branch) {
            $user->roles()->attach($role->id, ['branch_id' => $branch->id]);
        }
        $user->forceFill(['default_branch_id' => $branches[0]->id])->save();

        return $user->fresh();
    }

    public function test_switching_is_off_until_the_module_is_on(): void
    {
        $branches = app(CurrentBranch::class);
        $this->assertFalse($branches->multi());
        $this->assertNull($branches->scope());

        app(ModuleManager::class)->enable('multibranch');
        $this->assertTrue($branches->multi());
        $this->assertSame(3, $branches->allowed()->count(), 'The owner works in every branch');
    }

    public function test_a_user_switches_between_their_own_branches_only(): void
    {
        app(ModuleManager::class)->enable('multibranch');
        $user = $this->receptionistIn($this->north, $this->south);

        $this->signIn($user)->get(self::BASE.'/orders')->assertOk()->assertSee('dms-branch-switch', false)->assertSee('South branch');

        $this->post(self::BASE.'/branch', ['branch_id' => $this->south->id])->assertRedirect();
        $this->actingAs($user);
        $this->assertSame($this->south->id, app(CurrentBranch::class)->get()->id);
        $this->assertTrue(AuditLog::where('action', 'branch.switched')->exists());

        $this->post(self::BASE.'/branch', ['branch_id' => $this->branch->id])->assertForbidden();
        $this->assertSame($this->south->id, app(CurrentBranch::class)->get()->id);
    }

    public function test_lists_show_only_the_users_branches_starting_on_the_current_one(): void
    {
        $main = $this->orderIn($this->branch);
        $north = $this->orderIn($this->north);
        $south = $this->orderIn($this->south);

        $user = $this->receptionistIn($this->north, $this->south);
        $this->actingAs($user);

        // Module off: lists are not limited (as before).
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$main, $north, $south]);

        app(ModuleManager::class)->enable('multibranch');

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$north])->assertCanNotSeeTableRecords([$main, $south])
            ->filterTable('branch', null)
            ->assertCanSeeTableRecords([$north, $south])->assertCanNotSeeTableRecords([$main])
            ->filterTable('branch', $this->branch->id)
            ->assertCanNotSeeTableRecords([$main]);
    }

    public function test_a_user_of_one_branch_sees_only_it_and_gets_no_switcher(): void
    {
        app(ModuleManager::class)->enable('multibranch');
        $north = $this->orderIn($this->north);
        $south = $this->orderIn($this->south);
        $user = $this->receptionistIn($this->north);

        $this->signIn($user)->get(self::BASE.'/orders')->assertOk()->assertDontSee('dms-branch-switch', false);
        $this->actingAs($user);
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$north])->assertCanNotSeeTableRecords([$south]);
    }

    public function test_each_branch_prints_its_own_address_and_name(): void
    {
        $data = app(Letterhead::class)->data($this->north);
        $this->assertSame('Sonadanga, Khulna', $data['address']);
        $this->assertSame('01700111222', $data['phone']);
        $this->assertSame('North branch', $data['branch']);

        $south = app(Letterhead::class)->data($this->south);
        $this->assertSame(app(Letterhead::class)->data()['address'], $south['address'], 'No branch address: the clinic address');

        $order = $this->orderIn($this->north);
        $this->signIn($this->ownerUser)->get(self::BASE.'/print/invoice/'.$order->invoice->public_id)
            ->assertSee('Sonadanga, Khulna')->assertSee('North branch');
    }

    public function test_the_owner_compares_branches_and_picks_one(): void
    {
        $this->orderIn($this->north);
        $this->orderIn($this->north);
        $this->orderIn($this->south);
        $today = now('Asia/Dhaka')->toDateString();
        $this->artisan('frontdesk:summarize', ['--days' => 1]);

        $rows = collect(app(SalesReports::class)->byBranch($today, $today))->keyBy('name');
        $this->assertSame(2, $rows['North branch']['orders']);
        $this->assertSame('800.00', $rows['North branch']['billed']->toDecimal());
        $this->assertSame(1, $rows['South branch']['orders']);

        app(ModuleManager::class)->enable('owner');
        app(ModuleManager::class)->enable('multibranch');
        $page = Livewire::test(SalesReportsPage::class)->set('report', 'test')->set('branch', (string) $this->south->id);
        $page->assertSee('CBC STH')->assertDontSee('CBC NTH')->assertSee('South branch only.');
    }
}
