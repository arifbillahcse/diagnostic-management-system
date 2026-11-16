<?php

declare(strict_types=1);

namespace Modules\Reports\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Documents\ClinicFiles;
use App\Core\Documents\Letterhead;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicManager;
use App\Filament\Pages\ManageSettings;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Reports\Filament\ListReports;
use Modules\Reports\Filament\ManageSignatories;
use Modules\Reports\Filament\ReportSettings;
use Modules\Reports\Models\Signatory;
use Modules\Reports\Permissions;
use Modules\Reports\Support\ReportBuilder;

final class ScreensTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake(ClinicFiles::DISK);
    }

    public function test_the_report_opens_as_a_pdf_in_the_browser(): void
    {
        $item = $this->releasedCbc();

        $response = $this->signIn($this->staff('receptionist'))->get(self::BASE."/print/report/{$item->order->public_id}");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, AuditLog::where('action', 'report.printed')->count());
    }

    public function test_a_report_that_is_not_ready_explains_why_instead_of_failing(): void
    {
        $order = $this->labOrder([$this->cbc]);

        $this->signIn($this->ownerUser)->get(self::BASE."/print/report/{$order->public_id}")
            ->assertStatus(409)
            ->assertSee('No verified results are ready to print for this order.');

        $this->assertSame(0, AuditLog::where('action', 'report.printed')->count());
    }

    public function test_the_report_address_needs_a_signed_in_user_with_the_permission(): void
    {
        $item = $this->releasedCbc();
        $url = self::BASE."/print/report/{$item->order->public_id}";

        $this->signIn($this->userWithRoles())->get($url)->assertForbidden();

        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_the_list_shows_orders_with_something_to_print(): void
    {
        $ready = $this->releasedCbc()->order;

        $partly = $this->labOrder([$this->cbc, $this->urine]);
        $this->receiveSamples($partly);
        $this->enter($this->work($partly, $this->cbc), ['HB' => '14', 'WBC' => '7000']);
        $this->verify($this->work($partly, $this->cbc));

        $nothing = $this->labOrder([$this->glucose]);
        $this->actingAs($this->staff('receptionist'));

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$ready, $partly])
            ->assertCanNotSeeTableRecords([$nothing])
            ->assertSee('All ready')
            ->assertSee('1 of 2 ready')
            ->assertSee(route('reports.print', $ready->public_id), false);

        $this->signIn($this->staff('receptionist'))->get(self::BASE.'/lab/reports')->assertOk();
        $this->signIn($this->userWithRoles())->get(self::BASE.'/lab/reports')->assertForbidden();
    }

    public function test_the_layout_is_changed_from_settings(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(ReportSettings::class)
            ->assertFormSet(['letterhead' => 'print', 'block_print_when_due' => false])
            ->fillForm(['letterhead' => 'preprinted', 'top_margin_mm' => 55, 'bottom_margin_mm' => 30, 'footer_text' => 'Results relate only to the sample tested.', 'block_print_when_due' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Report layout saved');

        $settings = app(Settings::class);
        $this->assertSame('preprinted', $settings->get(Permissions::SETTING_LETTERHEAD));
        $this->assertSame(55, $settings->get(Permissions::SETTING_TOP_MARGIN));
        $this->assertTrue($settings->get(Permissions::SETTING_BLOCK_WHEN_DUE));
        $this->assertSame(55, AuditLog::where('action', 'settings.updated')->latest('id')->first()->after[Permissions::SETTING_TOP_MARGIN]);

        $this->actingAs($this->technician);
        Livewire::test(ReportSettings::class)->assertForbidden();
    }

    public function test_a_signatory_with_a_signature_image_appears_on_the_report(): void
    {
        $item = $this->releasedCbc();
        $this->actingAs($this->staff('admin'));

        Livewire::test(ManageSignatories::class)
            ->callAction('create', [
                'user_id' => $this->verifierUser->id,
                'display_name' => 'Dr. Nasrin Sultana',
                'qualifications' => "MBBS, MD (Pathology)\nConsultant Pathologist",
                'signature_path' => [UploadedFile::fake()->image('signature.png', 300, 90)],
            ])
            ->assertHasNoFormErrors();

        $signatory = Signatory::sole();
        $clinicId = app(ClinicContext::class)->id();
        $this->assertStringStartsWith("clinics/{$clinicId}/signatures/", $signatory->signature_path);
        Storage::disk(ClinicFiles::DISK)->assertExists($signatory->signature_path);

        $signer = app(ReportBuilder::class)->forOrder($item->order)['signers'][0];
        $this->assertSame('Dr. Nasrin Sultana', $signer['name']);
        $this->assertSame(['MBBS, MD (Pathology)', 'Consultant Pathologist'], $signer['lines']);
        $this->assertStringStartsWith('data:image/png;base64,', $signer['signature']);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->staff('admin'));

        Livewire::test(ManageSignatories::class)
            ->callAction('create', [
                'user_id' => $this->verifierUser->id,
                'display_name' => 'Dr. X',
                'signature_path' => [UploadedFile::fake()->create('signature.php', 10, 'application/x-php')],
            ])
            ->assertHasFormErrors(['signature_path']);

        $this->assertSame(0, Signatory::count());
    }

    public function test_the_clinic_logo_is_uploaded_and_used_on_the_letterhead(): void
    {
        $this->assertNull(app(Letterhead::class)->data()['logo']);

        Livewire::test(ManageSettings::class)
            ->fillForm(['name' => 'Alpha Diagnostics', 'logo_path' => [UploadedFile::fake()->image('logo.png', 200, 200)]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        $path = app(Settings::class)->get('clinic.logo_path');
        $this->assertStringStartsWith('clinics/'.app(ClinicContext::class)->id().'/branding/', $path);
        $this->assertStringStartsWith('data:image/png;base64,', app(Letterhead::class)->data()['logo']);
    }

    public function test_one_clinic_cannot_embed_another_clinics_file(): void
    {
        Livewire::test(ManageSettings::class)
            ->fillForm(['name' => 'Alpha Diagnostics', 'logo_path' => [UploadedFile::fake()->image('logo.png', 50, 50)]])
            ->call('save');
        $alphaLogo = app(Settings::class)->get('clinic.logo_path');

        // Beta's settings somehow point at Alpha's file.
        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($beta);
        app(Settings::class)->set('clinic.logo_path', $alphaLogo);

        $this->assertNull(app(Letterhead::class)->data()['logo']);
        $this->assertNull(app(ClinicFiles::class)->dataUri('clinics/'.$beta->id.'/../'.basename(dirname($alphaLogo, 2)).'/branding/x.png'));
    }
}
