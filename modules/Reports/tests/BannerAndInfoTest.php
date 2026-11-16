<?php

declare(strict_types=1);

namespace Modules\Reports\Tests;

use App\Core\Documents\ClinicFiles;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\Laboratory\Models\Sample;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Reports\Actions\PrintReport;
use Modules\Reports\Filament\ReportSettings;
use Modules\Reports\Permissions;
use Modules\Reports\Support\ReportBuilder;

final class BannerAndInfoTest extends LabTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake(ClinicFiles::DISK);
    }

    private function uploadBanners(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(ReportSettings::class)
            ->fillForm([
                'letterhead' => 'print',
                'header_image' => [UploadedFile::fake()->image('header.png', 1240, 250)],
                'footer_image' => [UploadedFile::fake()->image('footer.png', 1240, 100)],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    private function html($order): string
    {
        return view('reports::lab-report', app(ReportBuilder::class)->forOrder($order))->render();
    }

    public function test_banner_images_are_uploaded_and_given_room_on_every_page(): void
    {
        $this->uploadBanners();

        $settings = app(Settings::class);
        $this->assertStringContainsString('/branding/', $settings->get(Permissions::SETTING_HEADER_IMAGE));

        $item = $this->releasedCbc();
        $layout = app(ReportBuilder::class)->forOrder($item->order)['layout'];

        $this->assertSame(210.0, $layout['header_image']['width_mm']);
        $this->assertEqualsWithDelta(42.3, $layout['header_image']['height_mm'], 0.1);
        $this->assertEqualsWithDelta(16.9, $layout['footer_image']['height_mm'], 0.1);
        $this->assertSame(47, $layout['top_mm']);      // header 42.3 mm, rounded up, plus 4
        $this->assertSame(25, $layout['bottom_mm']);   // footer 16.9 mm, rounded up, plus 8

        $html = $this->html($item->order);
        $this->assertSame(2, substr_count($html, 'class="banner"'));
        $this->assertStringContainsString('margin: 47mm 13mm', $html);
        $this->assertStringNotContainsString('class="letterhead"', $html, 'the typed header is replaced by the banner');

        $this->actingAs($this->ownerUser);
        $pdf = app(PrintReport::class)->handle($item->order);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_without_banners_the_typed_header_is_kept(): void
    {
        $item = $this->releasedCbc();
        $data = app(ReportBuilder::class)->forOrder($item->order);

        $this->assertNull($data['layout']['header_image']);
        $this->assertNull($data['layout']['footer_image']);
        $this->assertStringContainsString('class="letterhead"', $this->html($item->order));
        $this->assertSame(12, $data['layout']['top_mm']);
    }

    public function test_a_banner_that_is_too_large_is_refused(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(ReportSettings::class)
            ->fillForm(['letterhead' => 'print', 'header_image' => [UploadedFile::fake()->image('header.jpg', 1240, 250)->size(Permissions::HEADER_IMAGE_MAX_KB + 50)]])
            ->call('save')
            ->assertHasFormErrors(['header_image']);

        Livewire::test(ReportSettings::class)
            ->fillForm(['letterhead' => 'print', 'footer_image' => [UploadedFile::fake()->image('footer.jpg', 1240, 100)->size(Permissions::FOOTER_IMAGE_MAX_KB + 20)]])
            ->call('save')
            ->assertHasFormErrors(['footer_image']);

        Livewire::test(ReportSettings::class)
            ->fillForm(['letterhead' => 'print', 'header_image' => [UploadedFile::fake()->create('header.pdf', 20, 'application/pdf')]])
            ->call('save')
            ->assertHasFormErrors(['header_image']);

        $this->assertNull(app(Settings::class)->get(Permissions::SETTING_HEADER_IMAGE));
    }

    public function test_pre_printed_paper_ignores_the_banners(): void
    {
        $this->uploadBanners();
        app(Settings::class)->set(Permissions::SETTING_LETTERHEAD, 'preprinted');

        $item = $this->releasedCbc();
        $layout = app(ReportBuilder::class)->forOrder($item->order)['layout'];

        $this->assertNull($layout['header_image']);
        $this->assertNull($layout['footer_image']);
        $this->assertSame(40, $layout['top_mm']);
        $this->assertStringNotContainsString('class="banner"', $this->html($item->order));
    }

    public function test_a_tall_banner_is_shown_at_the_height_limit_without_stretching(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(ReportSettings::class)
            ->fillForm(['letterhead' => 'print', 'header_image' => [UploadedFile::fake()->image('header.png', 600, 600)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $layout = app(ReportBuilder::class)->forOrder($this->releasedCbc()->order)['layout'];

        $this->assertSame(70.0, $layout['header_image']['height_mm']);
        $this->assertSame(70.0, $layout['header_image']['width_mm']);
    }

    public function test_the_watermark_needs_the_switch_and_a_logo(): void
    {
        $item = $this->releasedCbc();
        app(Settings::class)->set(Permissions::SETTING_WATERMARK, true);
        $this->assertNull(app(ReportBuilder::class)->forOrder($item->order)['layout']['watermark'], 'no logo yet');

        $this->actingAs($this->ownerUser);
        $path = 'clinics/'.app(ClinicContext::class)->id().'/branding/logo.png';
        Storage::disk(ClinicFiles::DISK)->put($path, UploadedFile::fake()->image('logo.png', 100, 100)->getContent());
        app(Settings::class)->set('clinic.logo_path', $path);

        $this->assertStringContainsString('class="watermark"', $this->html($item->order));

        app(Settings::class)->set(Permissions::SETTING_WATERMARK, false);
        $this->assertStringNotContainsString('class="watermark"', $this->html($item->order));
    }

    public function test_the_top_block_has_bill_and_lab_numbers_barcodes_specimen_and_dates(): void
    {
        $this->at('2026-11-13 10:40');
        $doctor = ReferralDoctor::create(['code' => '003', 'name' => 'Dr. Yeasmin Jahan', 'degree' => 'MBBS, FCPS']);
        $patient = $this->labPatient('female', '2001-06-01');

        $order = app(PlaceOrder::class)->handle($patient, $this->branch, [$this->cbc->id], ['referral_doctor_id' => $doctor->id]);
        $this->actingAs($this->technician);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '11.7', 'WBC' => '9700']);
        $this->verify($this->work($order, $this->cbc));

        $data = app(ReportBuilder::class)->forOrder($order);
        $info = $data['info'];
        $accession = Sample::where('order_id', $order->id)->value('accession_no');

        $this->assertSame($order->invoice->invoice_no, $info['bill_no']);
        $this->assertSame($accession, $info['lab_no']);
        $this->assertSame('Blood (EDTA)', $info['specimen']);
        $this->assertSame('003, Dr. Yeasmin Jahan, MBBS, FCPS', $info['consultant']);
        $this->assertNotNull($info['received_at']);
        $this->assertSame('2026-11-14 19:00', $info['delivery_at']->timezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->assertStringStartsWith('data:image/png;base64,', $info['bill_barcode']);
        $this->assertStringStartsWith('data:image/png;base64,', $info['lab_barcode']);

        $html = view('reports::lab-report', $data)->render();
        $this->assertSame(2, substr_count($html, 'src="data:image/png;base64,'));
        foreach (['Bill ID:', $order->invoice->invoice_no, 'Lab No:', $accession, 'UHID:', $patient->patient_code, 'Specimen:', 'Blood (EDTA)', 'Delivery date:', '14 Nov 2026, 07:00 PM', 'Received date:', '13 Nov 2026', '003, Dr. Yeasmin Jahan, MBBS, FCPS'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        CarbonImmutable::setTestNow();
    }
}
