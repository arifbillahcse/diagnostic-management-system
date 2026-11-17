<?php

declare(strict_types=1);

namespace Modules\Imaging\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Settings\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Support\FrontDeskException;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Models\ReportVersion;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Permissions;
use Modules\Imaging\Support\ImagingException;

class WorkflowTest extends ImagingTestCase
{
    public function test_billing_an_imaging_test_puts_it_on_the_list_lab_tests_do_not(): void
    {
        $order = $this->order([$this->usg, '400']);

        $study = Study::sole();
        $this->assertSame('pending', $study->status);
        $this->assertSame('USG whole abdomen', $study->study_name);
        $this->assertSame($order->patient_id, $study->patient_id);
    }

    public function test_imaging_tests_inside_a_package_and_added_later_are_listed(): void
    {
        $xray = $this->service('X-ray chest PA', '500', ServiceType::Imaging);
        $order = $this->order([$this->package('Health check', '2000', $this->usg, $this->service('CBC', '400'))]);
        $this->assertSame(1, Study::count());

        app(AdjustOrder::class)->addItem($order, $xray->id);
        $this->assertSame(['USG whole abdomen', 'X-ray chest PA'], Study::orderBy('id')->pluck('study_name')->all());
    }

    public function test_cancelling_a_test_cancels_its_study_until_the_report_is_started(): void
    {
        $order = $this->usgOrder();
        $item = $order->items()->firstOrFail();
        app(AdjustOrder::class)->cancelItem($item, 'Patient left');
        $this->assertSame('cancelled', Study::sole()->status);

        $order = $this->usgOrder();
        $study = $this->study($order);
        app(WriteReport::class)->saveDraft($study, ['findings' => '<p>Liver normal.</p>'], $study->lock_version);

        $this->expectException(FrontDeskException::class);
        app(AdjustOrder::class)->cancelItem($order->items()->firstOrFail(), 'Changed mind');
    }

    public function test_draft_sign_amend_sign_keeps_every_version(): void
    {
        $study = $this->study();

        $study = app(WriteReport::class)->saveDraft($study, ['findings' => '<p>Liver normal.</p>', 'impression' => ''], $study->lock_version);
        $this->assertSame('draft', $study->status);

        $study = $this->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>']);
        $this->assertSame('final', $study->status);
        $this->assertSame(1, $study->version);
        $this->assertNull($study->draft_findings);

        $study = app(WriteReport::class)->amend($study, 'Wrong side', $study->lock_version);
        $this->assertSame('amending', $study->status);
        $this->assertSame('<p>Normal.</p>', $study->draft_impression, 'The signed text is the starting point');

        $study = $this->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Mild fatty liver.</p>']);
        $this->assertSame(2, $study->version);

        $versions = ReportVersion::where('study_id', $study->id)->orderBy('version')->get();
        $this->assertSame(['<p>Normal.</p>', '<p>Mild fatty liver.</p>'], $versions->pluck('impression')->all());
        $this->assertSame('Wrong side', $versions[1]->amend_reason);
        $this->assertSame(['imaging.draft_saved', 'imaging.signed', 'imaging.amendment_started', 'imaging.amended'],
            AuditLog::where('action', 'like', 'imaging.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_signed_versions_cannot_be_changed_or_deleted(): void
    {
        $this->sign($this->study());

        foreach ([fn () => ReportVersion::first()->update(['impression' => 'x']), fn () => ReportVersion::first()->delete()] as $try) {
            try {
                $try();
                $this->fail('A signed report changed');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_signing_needs_findings_and_impression_and_a_signed_report_is_not_edited(): void
    {
        $study = $this->study();

        try {
            $this->sign($study, ['impression' => '<p></p>']);
            $this->fail('Signed without an impression');
        } catch (ImagingException $e) {
            $this->assertStringContainsString('findings and the impression', $e->getMessage());
        }

        $study = $this->sign($study);

        $this->expectExceptionMessage('Amend it');
        app(WriteReport::class)->saveDraft($study, ['findings' => '<p>x</p>'], $study->lock_version);
    }

    public function test_a_stale_screen_cannot_save_over_newer_work(): void
    {
        $study = $this->study();
        $old = $study->lock_version;
        app(WriteReport::class)->saveDraft($study, ['findings' => '<p>First</p>'], $old);

        $this->expectExceptionMessage('Someone else changed this report');
        app(WriteReport::class)->saveDraft($study, ['findings' => '<p>Second</p>'], $old);
    }

    public function test_the_second_signer_rule(): void
    {
        app(Settings::class)->set(Permissions::SETTING_SIGNER_MUST_DIFFER, true);
        $writer = $this->staff('verifier');
        $signer = $this->staff('verifier');
        $study = $this->study();

        $this->actingAs($writer);
        $study = app(WriteReport::class)->saveDraft($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>'], $study->lock_version);

        try {
            app(WriteReport::class)->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>'], $study->lock_version);
            $this->fail('The writer signed');
        } catch (ImagingException $e) {
            $this->assertStringContainsString('second person', $e->getMessage());
        }

        $this->actingAs($signer);
        $study = app(WriteReport::class)->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>'], $study->lock_version);
        $version = $study->latestVersion();
        $this->assertSame($writer->id, $version->written_by);
        $this->assertSame($signer->id, $version->signed_by);
    }

    public function test_report_text_is_cleaned_of_scripts_links_and_styles(): void
    {
        $study = $this->sign($this->study(), [
            'findings' => '<p style="color:red" onclick="x()">Liver <a href="http://evil">normal</a><script>alert(1)</script></p><img src="x" onerror="y"><ul><li>One</li></ul>',
            'impression' => 'Normal study.',
        ]);

        $version = $study->latestVersion();
        $this->assertSame('<p>Liver normal</p><ul><li>One</li></ul>', $version->findings);
        $this->assertSame('<p>Normal study.</p>', $version->impression);
    }

    public function test_writing_and_signing_need_their_permissions(): void
    {
        $study = $this->study();

        $this->actingAs($this->staff('receptionist'));
        try {
            app(WriteReport::class)->saveDraft($study, ['findings' => '<p>x</p>'], $study->lock_version);
            $this->fail('A receptionist wrote a report');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->staff('lab_technician'));
        $this->expectException(AuthorizationException::class);
        $this->sign($study);
    }
}
