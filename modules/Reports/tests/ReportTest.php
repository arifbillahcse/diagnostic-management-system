<?php

declare(strict_types=1);

namespace Modules\Reports\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Settings\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Laboratory\Actions\VerifyResults;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Reports\Actions\PrintReport;
use Modules\Reports\Models\Signatory;
use Modules\Reports\Permissions;
use Modules\Reports\Support\ReportBuilder;
use Modules\Reports\Support\ReportException;

final class ReportTest extends LabTestCase
{
    private function html($order): string
    {
        return view('reports::lab-report', app(ReportBuilder::class)->forOrder($order))->render();
    }

    public function test_a_report_holds_only_released_results_and_names_what_is_still_waiting(): void
    {
        $order = $this->labOrder([$this->cbc, $this->glucose, $this->urine], $this->labPatient('female'));
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '11.4', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));
        $this->enter($this->work($order, $this->glucose), ['RBS' => '15.2']); // entered, not verified

        $data = app(ReportBuilder::class)->forOrder($order);

        $this->assertSame(['Complete blood count'], $data['items']->pluck('test_name')->all());
        $this->assertEqualsCanonicalizing(['Random blood sugar', 'Urine R/E'], $data['waiting']);
        $this->assertSame(['Haematology'], $data['departments']->keys()->all());

        $html = $this->html($order);
        $this->assertStringContainsString('Haemoglobin', $html);
        $this->assertStringContainsString('12.0 - 15.0', $html);
        $this->assertStringContainsString('Not yet reported:', $html);
        $this->assertStringNotContainsString('15.2', $html, 'an unverified value must never be printed');
        $this->assertMatchesRegularExpression('/class="abnormal">\s*11\.4\s*<span class="mark">&nbsp;L<\/span>/', $html);
    }

    public function test_nothing_can_be_printed_before_a_result_is_verified(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14', 'WBC' => '7000']);
        $this->actingAs($this->ownerUser);

        $this->expectExceptionMessage('No verified results are ready to print for this order.');
        app(PrintReport::class)->handle($order);
    }

    public function test_printing_produces_a_pdf_and_is_recorded(): void
    {
        $item = $this->releasedCbc();
        $this->actingAs($this->staff('receptionist'));

        $pdf = app(PrintReport::class)->handle($item->order);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));

        $entry = AuditLog::where('action', 'report.printed')->sole();
        $this->assertSame(['Complete blood count (v1)'], $entry->context['tests']);
    }

    public function test_a_result_being_amended_is_withheld_and_the_amended_report_says_so(): void
    {
        $item = $this->releasedCbc('14.2');
        $order = $item->order;

        $this->actingAs($this->verifierUser);
        app(VerifyResults::class)->startAmendment($item, 'Wrong sample');

        try {
            app(PrintReport::class)->handle($order);
            $this->fail('A report under amendment must not be printable.');
        } catch (ReportException) {
            $this->addToAssertionCount(1);
        }

        $this->enter($item, ['HB' => '10.9', 'WBC' => '7500']);
        $this->verify($item);

        $html = $this->html($order);
        $this->assertStringContainsString('AMENDED REPORT', $html);
        $this->assertStringContainsString('version 2', $html);
        $this->assertStringContainsString('10.9', $html);
        $this->assertStringNotContainsString('14.2', $html);
    }

    public function test_the_verifier_is_named_with_their_qualifications(): void
    {
        $item = $this->releasedCbc();

        $this->assertStringContainsString($this->verifierUser->name, $this->html($item->order));

        Signatory::create([
            'user_id' => $this->verifierUser->id,
            'display_name' => 'Dr. Nasrin Sultana',
            'qualifications' => "MBBS, MD (Pathology)\n\nConsultant Pathologist",
        ]);

        $html = $this->html($item->order);
        $this->assertStringContainsString('Dr. Nasrin Sultana', $html);
        $this->assertStringContainsString('MBBS, MD (Pathology)', $html);
        $this->assertStringContainsString('Consultant Pathologist', $html);
    }

    public function test_pre_printed_paper_leaves_out_the_clinic_header_and_uses_the_set_margins(): void
    {
        $item = $this->releasedCbc();
        $settings = app(Settings::class);
        $settings->set('clinic.address', 'KDA Avenue, Khulna');

        $this->assertStringContainsString('KDA Avenue, Khulna', $this->html($item->order));

        $settings->set(Permissions::SETTING_LETTERHEAD, 'preprinted');
        $settings->set(Permissions::SETTING_TOP_MARGIN, 55);

        $html = $this->html($item->order);
        $this->assertStringNotContainsString('KDA Avenue, Khulna', $html);
        $this->assertStringContainsString('margin: 55mm 13mm', $html);
    }

    public function test_text_typed_by_staff_cannot_inject_markup_into_a_report(): void
    {
        $order = $this->labOrder([$this->urine]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->urine), ['UCOL' => '<script>alert(1)</script>', 'USUG' => 'Nil', 'UREM' => "<img src=x>\nSecond line"]);
        $this->verify($this->work($order, $this->urine));

        $html = $this->html($order);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_clinic_can_hold_reports_until_the_due_is_paid(): void
    {
        $item = $this->releasedCbc();
        $order = $item->order;
        $receptionist = $this->staff('receptionist');

        $this->actingAs($receptionist);
        $this->assertStringStartsWith('%PDF-', app(PrintReport::class)->handle($order), 'not blocked by default');

        app(Settings::class)->set(Permissions::SETTING_BLOCK_WHEN_DUE, true);

        try {
            app(PrintReport::class)->handle($order);
            $this->fail('The report should be held while there is a due.');
        } catch (ReportException $e) {
            $this->assertStringContainsString('due of 400.00', $e->getMessage());
        }

        // Someone allowed to override may still print.
        $this->actingAs($this->staff('accountant'));
        $this->assertStringStartsWith('%PDF-', app(PrintReport::class)->handle($order));

        // Once paid, anyone who can print may.
        $this->actingAs($this->ownerUser);
        $this->pay($order, '400');
        $this->actingAs($receptionist);
        $this->assertStringStartsWith('%PDF-', app(PrintReport::class)->handle($order));
    }

    public function test_printing_needs_the_permission(): void
    {
        $item = $this->releasedCbc();
        $this->actingAs($this->userWithRoles());

        $this->expectException(AuthorizationException::class);
        app(PrintReport::class)->handle($item->order);
    }
}
