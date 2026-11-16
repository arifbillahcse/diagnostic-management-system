<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Settings\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Laboratory\Enums\Flag;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Enums\WorkStatus;
use Modules\Laboratory\Filament\Catalog\CreateAnalyte;
use Modules\Laboratory\Filament\Catalog\CreateLabTest;
use Modules\Laboratory\Filament\Catalog\EditAnalyte;
use Modules\Laboratory\Filament\Catalog\ListLabTests;
use Modules\Laboratory\Filament\LabSettings;
use Modules\Laboratory\Filament\Samples\ListSamples;
use Modules\Laboratory\Filament\Work\ListWorkItems;
use Modules\Laboratory\Filament\Work\ResultsPage;
use Modules\Laboratory\Models\Analyte;
use Modules\Laboratory\Models\LabTest;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Permissions;

final class ScreensTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_screen_opens_for_the_owner(): void
    {
        $item = $this->releasedCbc();
        $this->actingAs($this->ownerUser);
        $test = LabTest::firstOrFail();

        foreach ([
            '/lab/samples', '/lab/worklist', "/lab/worklist/{$item->public_id}",
            '/lab-setup/tests', '/lab-setup/tests/create', "/lab-setup/tests/{$test->id}/edit",
            '/lab-setup/analytes', '/lab-setup/analytes/create', "/lab-setup/analytes/{$this->hb->id}/edit",
            '/lab-setup/sample-types', '/lab-setup/settings',
            "/print/sample-label/{$item->sample->public_id}",
        ] as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertOk();
        }
    }

    public function test_screens_follow_the_roles(): void
    {
        $item = $this->releasedCbc();

        foreach (['/lab/samples', '/lab/worklist', "/lab/worklist/{$item->public_id}"] as $path) {
            $this->signIn($this->technician)->get(self::BASE.$path)->assertOk();
        }
        foreach (['/lab-setup/tests', '/lab-setup/analytes', '/lab-setup/sample-types', '/lab-setup/settings'] as $path) {
            $this->signIn($this->technician)->get(self::BASE.$path)->assertForbidden();
        }

        $this->signIn($this->verifierUser)->get(self::BASE.'/lab-setup/analytes')->assertOk();
        $this->signIn($this->verifierUser)->get(self::BASE.'/lab-setup/settings')->assertForbidden();

        $accountant = $this->staff('accountant');
        foreach (['/lab/samples', '/lab/worklist', "/lab/worklist/{$item->public_id}", "/print/sample-label/{$item->sample->public_id}"] as $path) {
            $this->signIn($accountant)->get(self::BASE.$path)->assertForbidden();
        }
    }

    public function test_the_label_shows_the_patient_the_number_and_a_barcode(): void
    {
        $this->at('2026-11-17 09:00');
        $order = $this->labOrder([$this->cbc, $this->glucose]);
        $sample = Sample::firstOrFail();

        $response = $this->signIn($this->technician)->get(self::BASE."/print/sample-label/{$sample->public_id}");

        $response->assertOk()
            ->assertSee($order->patient->name)
            ->assertSee('2611170001')
            ->assertSee('Blood (EDTA)')
            ->assertSee('Complete blood count, Random blood sugar')
            ->assertSee('Print 2 labels')
            ->assertSee('<svg', false);

        $this->assertSame(2, substr_count($response->getContent(), 'class="label"'), 'one label per copy set on the sample type');
    }

    public function test_an_analyte_with_ranges_is_created_and_edited_from_the_screen(): void
    {
        Livewire::test(CreateAnalyte::class)
            ->fillForm([
                'code' => 'plt', 'name' => 'Platelet count', 'result_type' => 'numeric', 'unit' => '/cumm', 'decimals' => 0,
            ])
            ->set('data.ranges', [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 54750, 'low' => '150000', 'high' => '450000', 'critical_low' => '20000'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plt = Analyte::where('code', 'PLT')->sole();
        $range = $plt->ranges()->sole();
        $this->assertSame(['150000.000000', '450000.000000'], [number_format((float) $range->low, 6, '.', ''), number_format((float) $range->high, 6, '.', '')]);

        Livewire::test(EditAnalyte::class, ['record' => $plt->id])
            ->assertFormSet(['name' => 'Platelet count'])
            ->fillForm(['name' => 'Platelets'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Platelets', $plt->fresh()->name);
        $this->assertSame(1, $plt->ranges()->count());
    }

    public function test_a_choice_analyte_needs_its_answers(): void
    {
        Livewire::test(CreateAnalyte::class)
            ->fillForm(['code' => 'ubil', 'name' => 'Bilirubin (urine)', 'result_type' => 'option'])
            ->call('create')
            ->assertHasFormErrors(['options']);

        Livewire::test(CreateAnalyte::class)
            ->fillForm(['code' => 'ubil', 'name' => 'Bilirubin (urine)', 'result_type' => 'option', 'options' => ['Negative', 'Positive'], 'abnormal_options' => ['Positive']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Positive'], Analyte::where('code', 'UBIL')->sole()->abnormal_options);
    }

    public function test_a_lab_test_is_set_up_from_the_screen_and_missing_setups_are_listed(): void
    {
        $esr = $this->service('ESR', '200');
        $analyte = $this->analyte('ESR', 'ESR (Westergren)', 'mm/1st hr', 0, [['high' => '20']]);

        Livewire::test(ListLabTests::class)->assertSee('Still need a set-up')->assertSee('ESR');

        Livewire::test(CreateLabTest::class)
            ->fillForm(['service_id' => $esr->id, 'sample_type_id' => $this->edta->id, 'department' => 'Haematology'])
            ->set('data.analyteLinks', [['analyte_id' => $analyte->id, 'group_label' => null, 'is_required' => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $test = LabTest::where('service_id', $esr->id)->sole();
        $this->assertSame(['ESR (Westergren)'], $test->analytes->pluck('name')->all());

        Livewire::test(ListLabTests::class)->assertDontSee('Still need a set-up');
    }

    public function test_samples_are_collected_received_and_rejected_from_the_list(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $sample = Sample::firstOrFail();

        // A receptionist collects; the sample is not yet in the lab.
        $this->actingAs($this->staff('receptionist'));
        Livewire::test(ListSamples::class)
            ->assertCanSeeTableRecords([$sample])
            ->assertTableActionHidden('receive', $sample)
            ->callTableAction('collect', $sample)
            ->assertNotified('Sample collected');
        $this->assertSame(SampleStatus::Collected, $sample->fresh()->status);

        $this->actingAs($this->technician);
        Livewire::test(ListSamples::class)
            ->callTableAction('receive', $sample)
            ->assertNotified('Sample received')
            ->filterTable('status', [SampleStatus::Received->value])
            ->callTableAction('reject', $sample, ['reason' => 'Clotted'])
            ->assertNotified('Sample rejected');

        $this->assertSame(SampleStatus::Rejected, $sample->fresh()->status);
        $this->assertSame(WorkStatus::AwaitingSample, $this->work($order, $this->cbc)->status);

        // A technician collecting in the lab does both steps at once.
        Livewire::test(ListSamples::class)->callTableAction('collect', $sample, ['in_lab' => true])->assertNotified('Sample collected');
        $this->assertSame(SampleStatus::Received, $sample->fresh()->status);
    }

    public function test_a_scanned_label_finds_the_sample(): void
    {
        $this->labOrder([$this->cbc]);
        $this->labOrder([$this->urine]);
        [$first, $second] = Sample::orderBy('id')->get()->all();

        Livewire::test(ListSamples::class)
            ->searchTable($second->accession_no)
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);
    }

    public function test_the_worklist_tabs_show_what_needs_doing(): void
    {
        $waiting = $this->work($this->labOrder([$this->urine]), $this->urine);
        $ready = $this->labOrder([$this->cbc]);
        $this->receiveSamples($ready);
        $ready = $this->work($ready, $this->cbc);
        $entered = $this->labOrder([$this->glucose]);
        $this->receiveSamples($entered);
        $entered = $this->enter($this->work($entered, $this->glucose), ['RBS' => '6']);
        $this->actingAs($this->technician);

        Livewire::test(ListWorkItems::class)   // "To enter" is the first tab
            ->assertCanSeeTableRecords([$ready])->assertCanNotSeeTableRecords([$entered, $waiting]);
        Livewire::test(ListWorkItems::class, ['activeTab' => 'verify'])
            ->assertCanSeeTableRecords([$entered])->assertCanNotSeeTableRecords([$ready]);
        Livewire::test(ListWorkItems::class, ['activeTab' => 'waiting'])
            ->assertCanSeeTableRecords([$waiting])->assertTableActionHidden('open', $waiting);
    }

    public function test_results_are_entered_and_submitted_on_the_result_page(): void
    {
        $order = $this->labOrder([$this->cbc], $this->labPatient('female'));
        $this->receiveSamples($order);
        $item = $this->work($order, $this->cbc);
        $this->actingAs($this->technician);

        $page = Livewire::test(ResultsPage::class, ['record' => $item->public_id])
            ->assertSee('Haemoglobin')
            ->assertSee('12.0 - 15.0')      // the range for this patient, before anything is typed
            ->assertSee('Submit for verification')
            ->set("values.{$this->hb->id}.value", '11.25')
            ->call('save')
            ->assertNotified('Draft saved')
            ->assertSet("values.{$this->hb->id}.value", '11.25')   // exactly as typed, not rounded
            ->assertSee('Low');

        $page->call('submit')->assertNotified('Not saved');   // WBC missing

        $page->set("values.{$this->wbc->id}.value", 'abc')->call('submit')->assertNotified('Not saved');

        $page->set("values.{$this->wbc->id}.value", '7500')
            ->set("values.{$this->wbc->id}.comment", 'Rechecked')
            ->call('submit')
            ->assertNotified('Submitted for verification')
            ->assertDontSee('Submit for verification');

        $item->refresh();
        $this->assertSame(WorkStatus::Entered, $item->status);
        $this->assertSame(Flag::Low, $item->results()->where('analyte_id', $this->hb->id)->first()->flag);
        $this->assertSame('11.3', $item->results()->where('analyte_id', $this->hb->id)->first()->value_text, 'printed to one decimal');
        $this->assertSame('Rechecked', $item->results()->where('analyte_id', $this->wbc->id)->first()->comment);
    }

    public function test_a_stale_result_page_cannot_overwrite_newer_results(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $item = $this->work($order, $this->cbc);
        $this->actingAs($this->technician);

        $stale = Livewire::test(ResultsPage::class, ['record' => $item->public_id]);

        $this->enter($item, ['HB' => '14.0'], submit: false);
        $this->actingAs($this->technician);

        $stale->set("values.{$this->hb->id}.value", '9.0')->call('save')->assertNotified('Not saved');

        $this->assertSame('14.0', $item->results()->first()->value_text);
    }

    public function test_results_are_verified_sent_back_and_amended_from_the_result_page(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $item = $this->enter($this->work($order, $this->cbc), ['HB' => '41.2', 'WBC' => '7500']);

        // The technician sees no verify button.
        $this->actingAs($this->technician);
        Livewire::test(ResultsPage::class, ['record' => $item->public_id])
            ->assertActionHidden('verify')->assertActionHidden('sendBack')->assertActionHidden('amend');

        $this->actingAs($this->verifierUser);
        Livewire::test(ResultsPage::class, ['record' => $item->public_id])
            ->assertActionVisible('verify')
            ->callAction('sendBack', ['reason' => 'Haemoglobin looks mistyped'])
            ->assertNotified('Sent back to the technician');
        $this->assertSame(WorkStatus::Ready, $item->fresh()->status);

        $this->enter($item, ['HB' => '14.2', 'WBC' => '7500']);

        $this->actingAs($this->verifierUser);
        Livewire::test(ResultsPage::class, ['record' => $item->public_id])
            ->callAction('verify')
            ->assertNotified('Results verified and released')
            ->assertActionHidden('verify')
            ->assertActionVisible('amend')
            ->assertSee('Verified versions')
            ->callAction('amend', ['reason' => 'Wrong sample'])
            ->assertNotified('Reopened for amendment')
            ->assertSee('Being amended: Wrong sample');

        $this->assertSame(WorkStatus::Amending, $item->fresh()->status);
    }

    public function test_the_verification_rule_is_changed_from_settings(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(LabSettings::class)
            ->assertFormSet(['verifier_must_differ' => true])
            ->fillForm(['verifier_must_differ' => false])
            ->call('save')
            ->assertNotified('Settings saved');

        $this->assertFalse(app(Settings::class)->get(Permissions::SETTING_VERIFIER_MUST_DIFFER));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated'], 'clinic');
    }

    public function test_switching_the_laboratory_off_hides_its_screens(): void
    {
        $item = $this->releasedCbc();
        $this->disableModule('laboratory');

        foreach (['/lab/samples', '/lab/worklist', "/lab/worklist/{$item->public_id}", '/lab-setup/tests', '/lab-setup/analytes', '/lab/reports'] as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertForbidden();
        }

        $this->signIn($this->ownerUser)->get(self::BASE."/print/sample-label/{$item->sample->public_id}")->assertNotFound();
        $this->signIn($this->ownerUser)->get(self::BASE."/print/report/{$item->order->public_id}")->assertNotFound();
        $this->signIn($this->ownerUser)->get(self::BASE.'/orders')->assertOk();
    }
}
