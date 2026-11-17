<?php

declare(strict_types=1);

namespace Modules\Imaging\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Documents\Signatory;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Support\ImagingException;
use Modules\Imaging\Support\ReportData;

class PrintTest extends ImagingTestCase
{
    private function html($order): string
    {
        return view('imaging::pdf', app(ReportData::class)->forOrder($order))->render();
    }

    public function test_only_signed_reports_print(): void
    {
        $order = $this->usgOrder();

        $this->expectException(ImagingException::class);
        app(ReportData::class)->forOrder($order);
    }

    public function test_the_report_shows_patient_text_and_the_signer_with_qualifications(): void
    {
        $order = $this->usgOrder();
        $doctor = $this->staff('verifier');
        Signatory::create(['user_id' => $doctor->id, 'display_name' => 'Dr. Farhana Haque', 'qualifications' => "MBBS, DMU\nConsultant Sonologist"]);
        $this->actingAs($doctor);
        $this->sign($this->study($order), ['findings' => '<p><strong>Liver:</strong> Normal in size.</p>', 'impression' => '<p>Normal study.</p>', 'advice' => 'Follow up in 6 months']);

        $html = $this->html($order);
        foreach (['Rahim Uddin', 'USG whole abdomen', 'Findings', '<strong>Liver:</strong> Normal in size.', 'Normal study.', 'Follow up in 6 months', 'Dr. Farhana Haque', 'Consultant Sonologist'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Amended report', $html);
    }

    public function test_an_amended_report_says_so_and_prints_the_latest_text(): void
    {
        $order = $this->usgOrder();
        $study = $this->sign($this->study($order), ['impression' => '<p>Normal.</p>']);
        $study = app(WriteReport::class)->amend($study, 'Typing error', $study->lock_version);
        $this->sign($study, ['impression' => '<p>Mild fatty liver.</p>']);

        $html = $this->html($order);
        $this->assertStringContainsString('Mild fatty liver.', $html);
        $this->assertStringNotContainsString('<p>Normal.</p>', $html);
        $this->assertStringContainsString('Amended report (version 2', $html);
        $this->assertStringContainsString('Typing error', $html);
    }

    public function test_the_pdf_opens_for_staff_who_may_print_and_is_audited(): void
    {
        $order = $this->usgOrder();
        $study = $this->sign($this->study($order));

        $response = $this->signIn($this->staff('receptionist'))->get(self::BASE."/print/imaging/{$order->public_id}?study={$study->public_id}");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertTrue(AuditLog::where('action', 'imaging.printed')->exists());

        $this->signIn($this->staff('lab_technician'))->get(self::BASE."/print/imaging/{$order->public_id}")->assertForbidden();
    }

    public function test_an_unsigned_order_answers_with_a_reason_not_an_error(): void
    {
        $order = $this->usgOrder();

        $this->signIn($this->ownerUser)->get(self::BASE."/print/imaging/{$order->public_id}")
            ->assertStatus(409)->assertSee('No signed imaging report');
    }
}
