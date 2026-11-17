<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\SaveLabRoutes;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Filament\CollectionCentres;
use Modules\Laboratory\Filament\Samples\ListSamples;
use Modules\Laboratory\Filament\Samples\ListTransfers;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\Transfer;
use Modules\Laboratory\Support\LabException;
use Modules\Laboratory\Support\LabRoutes;
use Modules\Reports\Actions\PrintReport;

class CollectionCentreTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    private Branch $centre;

    private User $centreStaff;

    private User $mainLab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = Branch::create(['code' => 'CC1', 'name' => 'Rupsha collection centre']);
        app(SaveLabRoutes::class)->handle([$this->centre->id => $this->branch->id]);

        $this->centreStaff = $this->inBranch(['receptionist', 'lab_technician'], $this->centre);
        $this->mainLab = $this->inBranch(['lab_technician'], $this->branch);
    }

    /** @param list<string> $roles */
    private function inBranch(array $roles, Branch $branch): User
    {
        $user = $this->userWithRoles();
        foreach ($roles as $key) {
            $user->roles()->attach(Role::where('key', $key)->firstOrFail()->id, ['branch_id' => $branch->id]);
        }
        $user->forceFill(['default_branch_id' => $branch->id])->save();

        return $user->fresh();
    }

    private function centreOrder(): Order
    {
        $this->actingAs($this->ownerUser);

        return app(PlaceOrder::class)->handle($this->labPatient(), $this->centre, [$this->cbc->id]);
    }

    private function sampleOf(Order $order): Sample
    {
        return Sample::where('order_id', $order->id)->sole();
    }

    public function test_a_sample_taken_at_a_centre_is_tested_at_the_main_lab(): void
    {
        $order = $this->centreOrder();
        $sample = $this->sampleOf($order);

        $this->assertSame($this->centre->id, $sample->branch_id);
        $this->assertSame($this->branch->id, $sample->lab_branch_id);
        $this->assertSame($this->branch->id, $this->work($order, $this->cbc)->lab_branch_id);
        $this->assertTrue($sample->isSentAway());

        $own = Sample::where('order_id', $this->labOrder([$this->cbc])->id)->sole();
        $this->assertFalse($own->isSentAway(), 'A branch with its own lab is unchanged');
    }

    public function test_collect_send_receive_enter_verify_and_print_at_the_centre(): void
    {
        $order = $this->centreOrder();
        $sample = $this->sampleOf($order);

        $this->actingAs($this->centreStaff);
        try {
            app(HandleSample::class)->collect($sample, alsoReceive: true);
            $this->fail('A centre received a sample tested elsewhere');
        } catch (LabException $e) {
            $this->assertStringContainsString('send it there', $e->getMessage());
        }
        app(HandleSample::class)->collect($sample->fresh());

        $transfer = app(HandleSample::class)->send($this->centre, $this->branch, null, 'Courier: Rahim');
        $this->assertMatchesRegularExpression('/^TR-\d{4}-0001$/', $transfer->transfer_no);
        $this->assertSame(SampleStatus::InTransit, $sample->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'samples.sent')->exists());

        try {
            app(HandleSample::class)->receive($sample->fresh());
            $this->fail('The centre received for the main lab');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->mainLab);
        $this->assertSame(1, app(HandleSample::class)->receiveTransfer($transfer));
        $this->assertSame(SampleStatus::Received, $sample->fresh()->status);
        $this->assertSame(WorkStatus::Ready, $this->work($order, $this->cbc)->status);
        $this->assertSame('received', $transfer->fresh()->status);

        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500'], as: $this->mainLab);
        $verifier = $this->inBranch(['verifier'], $this->branch);
        $this->verify($this->work($order, $this->cbc), $verifier);
        $this->assertSame(WorkStatus::Released, $this->work($order, $this->cbc)->status);

        $this->actingAs($this->centreStaff);
        $this->assertStringStartsWith('%PDF', app(PrintReport::class)->handle($order));
    }

    public function test_the_centre_cannot_enter_results_for_the_main_lab(): void
    {
        $order = $this->centreOrder();
        $this->actingAs($this->centreStaff);
        app(HandleSample::class)->collect($this->sampleOf($order));
        app(HandleSample::class)->send($this->centre, $this->branch);
        $this->actingAs($this->mainLab);
        app(HandleSample::class)->receive($this->sampleOf($order));

        $this->expectException(AuthorizationException::class);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500'], as: $this->centreStaff);
    }

    public function test_a_sample_damaged_on_the_way_is_rejected_by_the_lab_and_the_transfer_closes(): void
    {
        $first = $this->centreOrder();
        $second = $this->centreOrder();
        $this->actingAs($this->centreStaff);
        app(HandleSample::class)->collect($this->sampleOf($first));
        app(HandleSample::class)->collect($this->sampleOf($second));
        $transfer = app(HandleSample::class)->send($this->centre, $this->branch);
        $this->assertSame(2, $transfer->samples()->count());

        $this->actingAs($this->mainLab);
        app(HandleSample::class)->reject($this->sampleOf($first), 'Tube broken on the way');
        $this->assertSame('in_transit', $transfer->fresh()->status);
        app(HandleSample::class)->receive($this->sampleOf($second));
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(WorkStatus::AwaitingSample, $this->work($first, $this->cbc)->status, 'It waits for a new collection');
    }

    public function test_nothing_to_send_and_no_chains_of_labs(): void
    {
        $this->actingAs($this->centreStaff);
        $this->expectExceptionMessage('No collected sample');
        try {
            app(HandleSample::class)->send($this->centre, $this->branch);
        } finally {
            $this->actingAs($this->ownerUser);
            $third = Branch::create(['code' => 'CC2', 'name' => 'Third']);
            try {
                app(SaveLabRoutes::class)->handle([$third->id => $this->centre->id, $this->centre->id => $this->branch->id]);
                $this->fail('A chain of labs was accepted');
            } catch (LabException $e) {
                $this->assertStringContainsString('sends its own samples away', $e->getMessage());
            }
        }
    }

    public function test_screens_routes_send_lists_and_the_courier_list(): void
    {
        app(ModuleManager::class)->enable('multibranch');
        $order = $this->centreOrder();

        Livewire::test(CollectionCentres::class)
            ->assertSee('Samples taken at Rupsha collection centre are tested at')
            ->call('save')->assertNotified('Saved');
        $this->assertSame($this->branch->id, app(LabRoutes::class)->labFor($this->centre->id), 'Saving the shown routes keeps them');

        $this->actingAs($this->centreStaff);
        app(HandleSample::class)->collect($this->sampleOf($order));
        Livewire::test(ListTransfers::class)
            ->assertActionVisible('send')
            ->callAction('send', ['note' => 'Box 3'])
            ->assertNotified('Samples sent');
        $transfer = Transfer::sole();

        $this->actingAs($this->mainLab);
        Livewire::test(ListSamples::class)->assertCanSeeTableRecords([$this->sampleOf($order)]);
        Livewire::test(ListTransfers::class)->assertCanSeeTableRecords([$transfer])
            ->callTableAction('receive', $transfer)->assertNotified('Samples received');

        $this->signIn($this->mainLab)->get(self::BASE.'/print/transfer/'.$transfer->public_id)
            ->assertOk()->assertSee($transfer->transfer_no)->assertSee($this->sampleOf($order)->accession_no)->assertSee('Box 3');
    }
}
