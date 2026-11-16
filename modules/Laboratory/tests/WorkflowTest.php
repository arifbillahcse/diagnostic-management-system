<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use LogicException;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\Laboratory\Actions\EnterResults;
use Modules\Laboratory\Actions\HandleSample;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Events\ResultsAmended;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\ResultVersion;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Models\WorkItem;
use Modules\Laboratory\Permissions;
use Modules\Laboratory\Support\LabException;

final class WorkflowTest extends LabTestCase
{
    private function samples(): HandleSample
    {
        return app(HandleSample::class);
    }

    public function test_an_order_becomes_work_items_grouped_onto_one_sample_per_tube(): void
    {
        $this->at('2026-11-17 09:00');
        $order = $this->labOrder([$this->cbc, $this->glucose, $this->urine]);

        $samples = Sample::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $samples, 'two blood tests share one tube; urine has its own');
        $this->assertSame(['2611170001', '2611170002'], $samples->pluck('accession_no')->all());
        $this->assertSame([SampleStatus::Pending, SampleStatus::Pending], $samples->pluck('status')->all());

        $work = WorkItem::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['Complete blood count', 'Random blood sugar', 'Urine R/E'], $work->pluck('test_name')->all());
        $this->assertSame([$samples[0]->id, $samples[0]->id, $samples[1]->id], $work->pluck('sample_id')->all());
        $this->assertTrue($work->every(fn (WorkItem $w) => $w->status === WorkStatus::AwaitingSample));
        $this->assertSame('Haematology', $work[0]->department);
    }

    public function test_tests_inside_a_package_get_work_and_tests_without_lab_setup_do_not(): void
    {
        $package = $this->package('Health check', '500', $this->cbc, $this->glucose);
        $noSetup = $this->service('Vitamin D', '2500');

        $order = $this->labOrder([$package, $noSetup]);

        $this->assertEqualsCanonicalizing(['Complete blood count', 'Random blood sugar'], WorkItem::where('order_id', $order->id)->pluck('test_name')->all());

        // Once the lab sets the test up, the sync creates the missing work.
        LabTest::create(['service_id' => $noSetup->id, 'sample_type_id' => $this->edta->id])
            ->analyteLinks()->create(['analyte_id' => $this->hb->id]);

        $this->artisan('laboratory:sync-work')->expectsOutputToContain('1 missing work item(s) created.')->assertSuccessful();
        $this->artisan('laboratory:sync-work')->expectsOutputToContain('0 missing work item(s) created.')->assertSuccessful();

        $this->assertSame(3, WorkItem::where('order_id', $order->id)->count());
        $this->assertSame(1, Sample::where('order_id', $order->id)->count(), 'it joined the tube that was still open');
    }

    public function test_a_sample_is_collected_then_received_and_its_tests_become_ready(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $sample = Sample::firstOrFail();

        $this->actingAs($this->staff('receptionist'));
        $collected = $this->samples()->collect($sample);
        $this->assertSame(SampleStatus::Collected, $collected->status);
        $this->assertNotNull($collected->collected_at);
        $this->assertSame(WorkStatus::AwaitingSample, $this->work($order, $this->cbc)->status);

        try {
            $this->samples()->receive($collected);
            $this->fail('A receptionist cannot receive samples in the lab.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->technician);
        $received = $this->samples()->receive($collected);

        $this->assertSame(SampleStatus::Received, $received->status);
        $this->assertSame($this->technician->id, $received->received_by);
        $this->assertSame(WorkStatus::Ready, $this->work($order, $this->cbc)->status);
        $this->assertSame(['created', 'collected', 'received'], $received->events()->pluck('event')->all());
        $this->assertSame(1, AuditLog::where('action', 'sample.received')->count());

        $this->expectException(LabException::class);
        $this->samples()->receive($received);
    }

    public function test_results_cannot_be_entered_before_the_sample_is_in_the_lab(): void
    {
        $order = $this->labOrder([$this->cbc]);

        $this->expectExceptionMessage('The sample for this test has not reached the laboratory yet.');
        $this->enter($this->work($order, $this->cbc), ['HB' => '14']);
    }

    public function test_results_are_saved_as_a_draft_then_submitted_with_flags_and_ranges(): void
    {
        $order = $this->labOrder([$this->cbc], $this->labPatient('female'));
        $this->receiveSamples($order);
        $item = $this->work($order, $this->cbc);

        $draft = $this->enter($item, ['HB' => '11.4'], submit: false);
        $this->assertSame(WorkStatus::Ready, $draft->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(SampleStatus::InProcess, Sample::firstOrFail()->status);

        try {
            $this->enter($item, ['HB' => '11.4']);
            $this->fail('Submitting with a required value missing should be refused.');
        } catch (LabException $e) {
            $this->assertSame('Enter these before submitting: Total WBC count.', $e->getMessage());
        }

        $submitted = $this->enter($item, ['HB' => '11.4', 'WBC' => '12500']);
        $this->assertSame(WorkStatus::Entered, $submitted->status);
        $this->assertSame($this->technician->id, $submitted->entered_by);

        $results = $submitted->results()->get()->keyBy('analyte_name');
        $this->assertSame(['11.4', 'g/dL', '12.0 - 15.0', Flag::Low], [$results['Haemoglobin']->value_text, $results['Haemoglobin']->unit, $results['Haemoglobin']->ref_text, $results['Haemoglobin']->flag]);
        $this->assertSame(['12500', Flag::High], [$results['Total WBC count']->value_text, $results['Total WBC count']->flag]);

        $entry = AuditLog::where('action', 'results.submitted')->sole();
        $this->assertSame('12500 [High]', $entry->after['Total WBC count']);
    }

    public function test_an_optional_value_may_be_left_empty(): void
    {
        $order = $this->labOrder([$this->urine]);
        $this->receiveSamples($order);

        $submitted = $this->enter($this->work($order, $this->urine), ['UCOL' => 'Straw', 'USUG' => 'Trace']);

        $this->assertSame(WorkStatus::Entered, $submitted->status);
        $this->assertSame(Flag::Abnormal, $submitted->results()->where('analyte_name', 'Sugar')->first()->flag);
        $this->assertSame(2, $submitted->results()->count());
    }

    public function test_a_catalog_change_after_entry_does_not_change_the_stored_result(): void
    {
        $item = $this->releasedCbc('14.2');

        $this->hb->update(['unit' => 'g/L', 'name' => 'Hb (renamed)']);
        $this->hb->ranges()->update(['low' => '1', 'high' => '2']);

        $result = $item->results()->where('analyte_id', $this->hb->id)->first();
        $this->assertSame(['Haemoglobin', 'g/dL', '13.0 - 17.0', Flag::Normal], [$result->analyte_name, $result->unit, $result->ref_text, $result->flag]);
    }

    public function test_two_people_saving_the_same_test_do_not_overwrite_each_other(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $item = $this->work($order, $this->cbc);
        $openedAt = $item->lock_version;

        $this->actingAs($this->technician);
        app(EnterResults::class)->handle($item, [$this->hb->id => ['value' => '14.0']], $openedAt);

        // A second person still has the form as it was when they opened it.
        try {
            app(EnterResults::class)->handle($item, [$this->hb->id => ['value' => '9.0']], $openedAt);
            $this->fail('The stale save should be refused.');
        } catch (LabException $e) {
            $this->assertStringContainsString('Someone else has changed these results', $e->getMessage());
        }

        $this->assertSame('14.0', $item->results()->first()->value_text);
    }

    public function test_verification_locks_the_results_keeps_a_version_and_completes_the_order(): void
    {
        Event::fake([ResultsReleased::class, ResultsAmended::class]);
        $order = $this->labOrder([$this->cbc, $this->glucose]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);

        $released = $this->verify($this->work($order, $this->cbc));

        $this->assertSame(WorkStatus::Released, $released->status);
        $this->assertSame(1, $released->version);
        $this->assertSame($this->verifierUser->id, $released->verified_by);
        $this->assertNotNull($released->released_at);

        $version = ResultVersion::sole();
        $this->assertSame(1, $version->version);
        $this->assertSame(['Haemoglobin', '14.2', 'normal'], [$version->snapshot[0]['analyte'], $version->snapshot[0]['value'], $version->snapshot[0]['flag']]);
        $this->assertNull($version->reason);
        Event::assertDispatched(ResultsReleased::class);
        Event::assertNotDispatched(ResultsAmended::class);

        // Locked.
        try {
            $this->enter($released, ['HB' => '9']);
            $this->fail('Released results must not be editable.');
        } catch (LabException $e) {
            $this->assertStringContainsString('verified and locked', $e->getMessage());
        }

        // One test still open: the order and the tube are not finished.
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(SampleStatus::InProcess, Sample::firstOrFail()->status);

        $this->enter($this->work($order, $this->glucose), ['RBS' => '6.1']);
        $this->verify($this->work($order, $this->glucose));

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(SampleStatus::Completed, Sample::firstOrFail()->status);
    }

    public function test_the_person_who_entered_results_cannot_verify_them_unless_the_clinic_allows_it(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $item = $this->enter($this->work($order, $this->cbc), ['HB' => '14', 'WBC' => '7000'], as: $this->verifierUser);

        try {
            $this->verify($item, $this->verifierUser);
            $this->fail('Self-verification should be refused by default.');
        } catch (LabException $e) {
            $this->assertStringContainsString('different person', $e->getMessage());
        }

        app(Settings::class)->set(Permissions::SETTING_VERIFIER_MUST_DIFFER, false);

        $this->assertSame(WorkStatus::Released, $this->verify($item, $this->verifierUser)->status);
    }

    public function test_only_a_verifier_can_verify_and_only_submitted_results(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $draft = $this->enter($this->work($order, $this->cbc), ['HB' => '14', 'WBC' => '7000'], submit: false);

        try {
            $this->verify($draft);
            $this->fail('A draft cannot be verified.');
        } catch (LabException) {
            $this->addToAssertionCount(1);
        }

        $submitted = $this->enter($draft, ['HB' => '14', 'WBC' => '7000']);

        foreach ([$this->technician, $this->staff('admin')] as $notAVerifier) {
            try {
                $this->verify($submitted, $notAVerifier);
                $this->fail('Only the verifier role verifies.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(WorkStatus::Entered, $submitted->fresh()->status);
    }

    public function test_the_verifier_can_send_results_back_with_a_reason(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $item = $this->enter($this->work($order, $this->cbc), ['HB' => '41.2', 'WBC' => '7000']);

        $this->actingAs($this->verifierUser);
        $back = app(VerifyResults::class)->sendBack($item, 'Haemoglobin looks mistyped');

        $this->assertSame(WorkStatus::Ready, $back->status);
        $this->assertSame('Sent back: Haemoglobin looks mistyped', $back->pending_reason);

        $fixed = $this->enter($back, ['HB' => '14.2', 'WBC' => '7000']);
        $this->assertSame(WorkStatus::Entered, $fixed->status);

        $this->actingAs($this->verifierUser);
        $this->expectExceptionMessage('A reason is required.');
        app(VerifyResults::class)->sendBack($fixed, ' ');
    }

    public function test_an_amendment_keeps_the_old_version_and_releases_a_new_one(): void
    {
        Event::fake([ResultsAmended::class]);
        $item = $this->releasedCbc('14.2', '7500');
        $order = $item->order;
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        try {
            $this->actingAs($this->technician);
            app(VerifyResults::class)->startAmendment($item, 'Typo');
            $this->fail('A technician cannot reopen a released result.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->verifierUser);
        $amending = app(VerifyResults::class)->startAmendment($item, 'Haemoglobin was read from the wrong sample');

        $this->assertSame(WorkStatus::Amending, $amending->status);
        $this->assertSame(1, $amending->version, 'still version 1 until the correction is verified');

        $resubmitted = $this->enter($amending, ['HB' => '10.9', 'WBC' => '7500']);
        $this->assertSame(WorkStatus::Entered, $resubmitted->status);

        $amended = $this->verify($resubmitted);

        $this->assertSame(WorkStatus::Released, $amended->status);
        $this->assertSame(2, $amended->version);
        $this->assertSame('10.9', $amended->results()->where('analyte_id', $this->hb->id)->first()->value_text);
        $this->assertSame(Flag::Low, $amended->results()->where('analyte_id', $this->hb->id)->first()->flag);

        $versions = ResultVersion::orderBy('version')->get();
        $this->assertSame(['14.2', '10.9'], $versions->map(fn ($v) => $v->snapshot[0]['value'])->all());
        $this->assertSame([null, 'Haemoglobin was read from the wrong sample'], $versions->pluck('reason')->all());

        $this->assertSame(1, AuditLog::where('action', 'results.amendment_started')->count());
        $this->assertSame('Haemoglobin was read from the wrong sample', AuditLog::where('action', 'results.amended')->sole()->context['reason']);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        Event::assertDispatched(ResultsAmended::class);
    }

    public function test_verified_versions_cannot_be_changed_or_deleted(): void
    {
        $this->releasedCbc();
        $version = ResultVersion::sole();

        foreach ([fn () => $version->update(['reason' => 'x']), fn () => $version->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A verified version must be permanent.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejecting_a_sample_discards_unverified_results_and_asks_for_a_new_collection(): void
    {
        $order = $this->labOrder([$this->cbc, $this->glucose]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);

        $this->actingAs($this->technician);

        try {
            $this->samples()->reject(Sample::firstOrFail(), '');
            $this->fail('Rejecting needs a reason.');
        } catch (LabException) {
            $this->addToAssertionCount(1);
        }

        $rejected = $this->samples()->reject(Sample::firstOrFail(), 'Clotted');

        $this->assertSame(SampleStatus::Rejected, $rejected->status);
        $this->assertSame('Clotted', $rejected->rejection_reason);

        $cbc = $this->work($order, $this->cbc);
        $this->assertSame(WorkStatus::AwaitingSample, $cbc->status);
        $this->assertSame(0, $cbc->results()->count());
        $this->assertSame(WorkStatus::AwaitingSample, $this->work($order, $this->glucose)->status);
        $this->assertSame('14.2', AuditLog::where('action', 'results.discarded')->sole()->before['Haemoglobin']);

        // Collected again under the same accession number.
        $again = $this->samples()->collect($rejected, alsoReceive: true);
        $this->assertSame(SampleStatus::Received, $again->status);
        $this->assertSame($rejected->accession_no, $again->accession_no);
        $this->assertSame(WorkStatus::Ready, $this->work($order, $this->cbc)->status);
    }

    public function test_a_sample_with_verified_results_cannot_be_rejected(): void
    {
        $item = $this->releasedCbc();
        $this->actingAs($this->technician);

        $this->expectExceptionMessage('have already been verified');
        $this->samples()->reject($item->sample, 'Haemolysed');
    }

    public function test_cancelling_a_test_at_the_front_desk_cancels_its_lab_work(): void
    {
        $order = $this->labOrder([$this->cbc, $this->urine]);

        app(AdjustOrder::class)->cancelItem($order->items[1], 'Patient could not give a sample');

        $this->assertSame(WorkStatus::Cancelled, $this->work($order, $this->urine)->status);
        $this->assertSame(WorkStatus::AwaitingSample, $this->work($order, $this->cbc)->status);
        $this->assertSame([SampleStatus::Pending, SampleStatus::Cancelled], Sample::orderBy('id')->pluck('status')->all());
    }

    public function test_cancelling_an_order_cancels_all_of_its_lab_work(): void
    {
        $order = $this->labOrder([$this->cbc, $this->glucose, $this->urine]);

        app(AdjustOrder::class)->cancelOrder($order, 'Wrong patient');

        $this->assertTrue(WorkItem::all()->every(fn (WorkItem $w) => $w->status === WorkStatus::Cancelled));
        $this->assertTrue(Sample::all()->every(fn (Sample $s) => $s->status === SampleStatus::Cancelled));
    }

    public function test_the_front_desk_cannot_cancel_a_test_the_lab_has_results_for(): void
    {
        $order = $this->labOrder([$this->cbc, $this->glucose]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14'], submit: false);
        $this->actingAs($this->ownerUser);

        try {
            app(AdjustOrder::class)->cancelItem($order->items[0], 'Changed mind');
            $this->fail('A draft exists; the lab must be asked first.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('started entering results for Complete blood count', $e->getMessage());
        }

        $this->enter($this->work($order, $this->cbc), ['HB' => '14', 'WBC' => '7000']);
        $this->verify($this->work($order, $this->cbc));
        $this->actingAs($this->ownerUser);

        try {
            app(AdjustOrder::class)->cancelItem($order->items[0], 'Changed mind');
            $this->fail('A released result cannot be cancelled away.');
        } catch (FrontDeskException $e) {
            $this->assertStringContainsString('already has results for Complete blood count', $e->getMessage());
        }

        $this->assertInvoice($order, '550.00', '0.00', '0.00', '550.00', 'issued');
        $this->assertSame(WorkStatus::Released, $this->work($order, $this->cbc)->status);

        // The untouched test can still be cancelled.
        app(AdjustOrder::class)->cancelItem($order->items[1], 'Not needed');
        $this->assertSame(WorkStatus::Cancelled, $this->work($order, $this->glucose)->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_a_test_added_later_joins_the_open_tube_and_is_ready_at_once_if_it_is_in_the_lab(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $this->actingAs($this->ownerUser);

        app(AdjustOrder::class)->addItem($order, $this->glucose->id);

        $this->assertSame(1, Sample::count());
        $this->assertSame(WorkStatus::Ready, $this->work($order, $this->glucose)->status);
    }

    public function test_the_laboratory_does_nothing_while_it_is_switched_off(): void
    {
        $this->disableModule('laboratory');

        $order = $this->labOrder([$this->cbc]);

        $this->assertSame(0, WorkItem::count());
        $this->assertSame(0, Sample::count());

        // Switched back on, the sync picks the order up.
        app(ModuleManager::class)->enable('laboratory');
        $this->artisan('laboratory:sync-work')->assertSuccessful();

        $this->assertSame(1, WorkItem::where('order_id', $order->id)->count());
    }
}
