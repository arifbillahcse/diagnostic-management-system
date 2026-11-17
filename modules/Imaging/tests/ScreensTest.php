<?php

declare(strict_types=1);

namespace Modules\Imaging\Tests;

use App\Core\Settings\Settings;
use Livewire\Livewire;
use Modules\Imaging\Actions\AddStarterTemplates;
use Modules\Imaging\Filament\ListStudies;
use Modules\Imaging\Filament\ManagePhrases;
use Modules\Imaging\Filament\ManageTemplates;
use Modules\Imaging\Filament\ReportStudy;
use Modules\Imaging\Models\Phrase;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ImagingException;

class ScreensTest extends ImagingTestCase
{
    public function test_templates_are_made_by_hand_and_from_the_examples(): void
    {
        $this->signIn($this->ownerUser)->get(self::BASE.'/imaging/templates')->assertOk();

        Livewire::test(ManageTemplates::class)
            ->callAction('create', ['service_id' => $this->usg->id, 'name' => 'Normal', 'sex' => 'any', 'findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>', 'sort_order' => 0, 'is_active' => true])
            ->assertHasNoActionErrors();

        Livewire::test(ManageTemplates::class)
            ->callAction('create', ['service_id' => $this->usg->id, 'name' => 'Normal', 'sex' => 'any', 'findings' => '<p>x</p>', 'impression' => '<p>y</p>'])
            ->assertHasActionErrors(['name']);

        Livewire::test(ManageTemplates::class)
            ->callAction('starter', ['service_id' => $this->usg->id, 'set' => 'usg-whole-abdomen'])
            ->assertNotified('Example templates added');

        $this->assertSame(['Normal', 'Fatty liver (grade I)', 'Cholelithiasis'], Template::orderBy('id')->pluck('name')->all(), 'An existing name is skipped');
        $this->assertGreaterThan(0, Phrase::count());

        $lab = $this->service('CBC', '400');
        Livewire::test(ManageTemplates::class)
            ->callAction('starter', ['service_id' => $lab->id, 'set' => 'ecg'])
            ->assertHasActionErrors(['service_id']);

        try {
            app(AddStarterTemplates::class)->handle($lab, 'ecg');
            $this->fail('Examples added to a lab test');
        } catch (ImagingException $e) {
            $this->assertStringContainsString('not an imaging service', $e->getMessage());
        }
    }

    public function test_the_report_page_uses_a_template_a_phrase_saves_and_signs(): void
    {
        $this->template('Normal');
        $this->template('Female only', 'female');
        Phrase::create(['title' => 'No free fluid', 'body' => 'No free fluid in the abdomen.']);
        $study = $this->study();

        $this->signIn($this->ownerUser)->get(self::BASE."/imaging/reports/{$study->public_id}")->assertOk()->assertSee('Rahim Uddin');

        $page = Livewire::test(ReportStudy::class, ['record' => $study->public_id]);
        $this->assertSame(['Normal'], array_values($page->instance()->templateOptions()), 'A female-only template is not offered for a male patient');

        $page->callAction('template', ['template_id' => Template::where('name', 'Normal')->value('id')])
            ->callAction('phrase', ['phrase_id' => Phrase::value('id')])
            ->callAction('save')
            ->assertNotified('Draft saved');

        $study->refresh();
        $this->assertSame('draft', $study->status);
        $this->assertStringContainsString('No free fluid in the abdomen.', $study->draft_findings);
        $this->assertSame(Template::where('name', 'Normal')->value('id'), $study->draft_template_id);

        Livewire::test(ReportStudy::class, ['record' => $study->public_id])
            ->callAction('sign')
            ->assertNotified('Report signed')
            ->assertActionVisible('print')
            ->assertActionVisible('amend')
            ->assertActionHidden('save');

        $this->assertSame('final', $study->fresh()->status);
    }

    public function test_amending_from_the_page_and_a_refusal_shows_the_reason(): void
    {
        $study = $this->sign($this->study());

        Livewire::test(ReportStudy::class, ['record' => $study->public_id])
            ->callAction('amend', ['reason' => 'Wrong measurement'])
            ->assertNotified('Report opened for amendment')
            ->assertSee('Wrong measurement');

        $this->assertSame('amending', $study->fresh()->status);

        $fresh = $this->study($this->usgOrder());
        Livewire::test(ReportStudy::class, ['record' => $fresh->public_id])
            ->callAction('sign')
            ->assertNotified('Not done');
    }

    public function test_the_list_and_who_sees_what(): void
    {
        $study = $this->study();
        $this->signIn($this->ownerUser)->get(self::BASE.'/imaging/reports')->assertOk()->assertSee('USG whole abdomen');

        Livewire::test(ListStudies::class)->assertCanSeeTableRecords([$study])->assertTableActionHidden('print', $study);

        $this->actingAs($this->staff('receptionist'));
        Livewire::test(ReportStudy::class, ['record' => $study->public_id])
            ->assertActionHidden('save')->assertActionHidden('sign')->assertSee('Only a reporting doctor');

        $this->signIn($this->staff('lab_technician'))->get(self::BASE.'/imaging/reports')->assertForbidden();
        $this->signIn($this->staff('receptionist'))->get(self::BASE.'/imaging/templates')->assertForbidden();
    }

    public function test_the_second_signer_setting_and_phrases_screen(): void
    {
        Livewire::test(ListStudies::class)->callAction('settings', ['must_differ' => true]);
        $this->assertTrue(app(Settings::class)->get(Permissions::SETTING_SIGNER_MUST_DIFFER));

        Livewire::test(ManagePhrases::class)
            ->callAction('create', ['title' => 'Gas', 'body' => 'Limited by bowel gas.', 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('Limited by bowel gas.', Phrase::where('title', 'Gas')->value('body'));
    }
}
