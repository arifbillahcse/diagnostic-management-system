<?php

declare(strict_types=1);

namespace Modules\Imaging\Tests;

use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Filament\Orders\ViewOrder;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Support\OrderReports;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Events\ReportSigned;
use Modules\Imaging\Filament\ImagingStatusWidget;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Models\Template;
use Modules\Imaging\Support\LabReports;
use Modules\Imaging\Support\ReportData;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Reports\Permissions as ReportPermissions;

/** Imaging reports alongside lab reports: the order page, one combined file, the due rule. */
class DeliveryTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    private Service $usg;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('imaging');
        $this->usg = $this->service('USG whole abdomen', '1200', ServiceType::Imaging);
    }

    /** An order with a blood count and an ultrasound. */
    private function mixedOrder(): Order
    {
        $order = $this->labOrder([$this->cbc, $this->usg]);
        $this->receiveSamples($order);

        return $order;
    }

    private function releaseLab(Order $order): void
    {
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));
    }

    private function signImaging(Order $order, string $impression = '<p>Normal study.</p>'): Study
    {
        $this->actingAs($this->verifierUser);
        $study = Study::where('order_id', $order->id)->firstOrFail();

        return app(WriteReport::class)->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => $impression], $study->lock_version);
    }

    /** @return array<string, array<string, mixed>> label => row */
    private function rows(Order $order): array
    {
        $rows = [];
        foreach (app(OrderReports::class)->for($order) as $row) {
            $rows[preg_replace('/ \(.*$/', '', $row['label'])] = $row;
        }

        return $rows;
    }

    public function test_the_order_page_lists_lab_and_imaging_reports_with_print_buttons(): void
    {
        $order = $this->mixedOrder();
        $this->actingAs($this->ownerUser);

        $rows = $this->rows($order);
        $this->assertSame('Waiting', $rows['Lab report']['status']);
        $this->assertSame('To report', $rows['USG whole abdomen']['status']);
        $this->assertNull($rows['USG whole abdomen']['url']);
        $this->assertArrayNotHasKey('All ready reports in one file', $rows);

        $this->releaseLab($order);
        $this->signImaging($order);
        $this->actingAs($this->ownerUser);

        $rows = $this->rows($order);
        $this->assertSame('Ready', $rows['Lab report']['status']);
        $this->assertSame('Signed', $rows['USG whole abdomen']['status']);
        $this->assertNotNull($rows['USG whole abdomen']['url']);
        $this->assertStringContainsString('/print/reports/'.$order->public_id, $rows['All ready reports in one file']['url']);

        Livewire::test(ViewOrder::class, ['record' => $order->public_id])
            ->assertSee('Reports')->assertSee('USG whole abdomen')->assertSee('All ready reports in one file');
    }

    public function test_one_file_holds_the_lab_results_and_the_imaging_report(): void
    {
        $order = $this->mixedOrder();
        $this->releaseLab($order);
        $this->signImaging($order, '<p>Mild fatty liver.</p>');
        $this->actingAs($this->ownerUser);

        $data = app(ReportData::class)->forOrder($order);
        $data['lab'] = app(LabReports::class)->dataFor($order);
        $html = view('imaging::pdf', $data)->render();

        foreach (['Complete blood count', 'Haemoglobin', '14.2', 'USG whole abdomen', 'Mild fatty liver.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertLessThan(strpos($html, 'Mild fatty liver.'), strpos($html, 'Haemoglobin'), 'Lab results come first');
        $this->assertSame(2, substr_count($html, 'class="letterhead"'), 'The clinic header starts the imaging page too');

        $response = $this->signIn($this->staff('receptionist'))->get(self::BASE."/print/reports/{$order->public_id}");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_due_rule_applies_to_imaging_prints_too(): void
    {
        $order = $this->mixedOrder(); // placed with nothing paid, so the whole bill is due
        $this->signImaging($order);
        app(Settings::class)->set(ReportPermissions::SETTING_BLOCK_WHEN_DUE, true);

        $this->signIn($this->staff('receptionist'))->get(self::BASE."/print/imaging/{$order->public_id}")
            ->assertStatus(409)->assertSee('Collect the due');

        $this->signIn($this->staff('accountant'))->get(self::BASE."/print/imaging/{$order->public_id}")->assertOk();
    }

    public function test_signing_announces_the_report_after_it_is_saved(): void
    {
        Event::fake([ReportSigned::class]);
        $order = $this->mixedOrder();
        $study = $this->signImaging($order);

        Event::assertDispatched(ReportSigned::class, fn (ReportSigned $e): bool => $e->study->is($study) && $e->study->status === 'final');
    }

    public function test_imaging_tests_billed_before_the_module_was_on_are_added_by_the_sync(): void
    {
        $this->disableModule('imaging');
        $order = $this->labOrder([$this->usg]);
        $this->assertSame(0, Study::count());

        app(ModuleManager::class)->enable('imaging');
        $this->artisan('imaging:sync-studies')->assertSuccessful();

        $this->assertSame('pending', Study::where('order_id', $order->id)->sole()->status);
        $this->artisan('imaging:sync-studies')->expectsOutputToContain('0 missing')->assertSuccessful();
    }

    public function test_the_dashboard_counts_waiting_reports_and_doctors_get_an_open_button(): void
    {
        $order = $this->mixedOrder();
        $this->actingAs($this->ownerUser);
        $this->assertSame(['pending' => 1, 'drafts' => 0, 'late' => 0], ImagingStatusWidget::figures());

        $this->travelTo($order->delivery_at->addHour());
        $this->assertSame(1, ImagingStatusWidget::figures()['late']);

        $this->actingAs($this->verifierUser);
        $this->assertTrue(ImagingStatusWidget::canView());
        $this->assertNotNull($this->rows($order)['USG whole abdomen']['open_url']);

        $this->actingAs($this->staff('receptionist'));
        $this->assertFalse(ImagingStatusWidget::canView());
        $this->assertNull($this->rows($order)['USG whole abdomen']['open_url'] ?? null);
    }

    public function test_the_sample_data_adds_templates_and_signs_a_few_reports(): void
    {
        $this->service('X-ray chest P/A view', '500', ServiceType::Imaging);
        $this->mixedOrder();
        $clinic = app(ClinicContext::class)->require();

        $this->artisan('imaging:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertSuccessful();

        $this->assertSame(['Normal', 'Fatty liver (grade I)', 'Cholelithiasis'], Template::where('service_id', $this->usg->id)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(1, Study::where('status', 'final')->count());
        $this->artisan('imaging:sample-data', ['clinic' => $clinic->id, '--force' => true])->assertFailed();
    }
}
