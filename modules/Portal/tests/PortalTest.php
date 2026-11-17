<?php

declare(strict_types=1);

namespace Modules\Portal\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Models\Study;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Reports\Permissions as ReportPermissions;

class PortalTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('portal');
    }

    private function released(): Order
    {
        return $this->releasedCbc()->order;
    }

    private function asPatient(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    private function lookup(Order $order, string $mobile = '01711000000')
    {
        return $this->post(self::BASE.'/reports', ['bill_no' => $order->invoice->invoice_no, 'mobile' => $mobile]);
    }

    public function test_a_patient_finds_their_bill_and_downloads_the_lab_report(): void
    {
        $order = $this->released();
        $this->asPatient();

        $this->get(self::BASE.'/reports')->assertOk()->assertSee('Your reports')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->lookup($order, '+880 1711-000000')->assertRedirect(self::BASE.'/reports/'.$order->public_id);

        $this->get(self::BASE.'/reports/'.$order->public_id)->assertOk()
            ->assertSee($order->patient->name)->assertSee('Lab tests')->assertSee('Download lab report (PDF)');

        $pdf = $this->get(self::BASE.'/reports/'.$order->public_id.'/lab');
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertSame(['portal.opened', 'portal.downloaded'], AuditLog::where('action', 'like', 'portal.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_wrong_pair_says_nothing_about_which_part_was_wrong(): void
    {
        $order = $this->released();
        $this->asPatient();

        $wrongMobile = $this->lookup($order, '01811999999');
        $wrongBill = $this->post(self::BASE.'/reports', ['bill_no' => '9999-99999', 'mobile' => '01711000000']);

        foreach ([$wrongMobile, $wrongBill] as $response) {
            $response->assertStatus(422)->assertSee('We could not find a bill with that bill number and mobile number');
        }
    }

    public function test_a_report_page_needs_the_lookup_first_and_ends_when_the_patient_leaves(): void
    {
        $order = $this->released();
        $this->asPatient();

        $this->get(self::BASE.'/reports/'.$order->public_id)->assertRedirect(self::BASE.'/reports');
        $this->get(self::BASE.'/reports/'.$order->public_id.'/lab')->assertRedirect(self::BASE.'/reports');

        $this->lookup($order);
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertOk();

        $this->post(self::BASE.'/reports/leave')->assertRedirect(self::BASE.'/reports');
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertRedirect(self::BASE.'/reports');

        $this->lookup($order);
        $this->travel(31)->minutes();
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertRedirect(self::BASE.'/reports');
    }

    public function test_guessing_one_bill_number_is_stopped_after_five_wrong_tries(): void
    {
        $order = $this->released();
        $this->asPatient();

        for ($i = 0; $i < 5; $i++) {
            $this->lookup($order, '0181100000'.$i)->assertStatus(422);
        }

        $this->lookup($order)->assertStatus(429)->assertSee('Too many tries');
    }

    public function test_a_cancelled_order_is_not_shown(): void
    {
        $order = $this->labOrder([$this->cbc]);
        app(AdjustOrder::class)->cancelOrder($order, 'Patient left');
        $this->asPatient();

        $this->lookup($order->fresh())->assertStatus(422);
    }

    public function test_nothing_ready_and_held_for_a_due(): void
    {
        $order = $this->labOrder([$this->cbc]);
        $this->asPatient();
        $this->lookup($order);
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertOk()->assertSee('not ready yet')->assertDontSee('Download');

        $this->actingAs($this->ownerUser);
        $order = $this->released();
        app(Settings::class)->set(ReportPermissions::SETTING_BLOCK_WHEN_DUE, true);
        $this->asPatient();
        $this->lookup($order);
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertOk()->assertSee('Please contact the clinic to collect it')->assertDontSee('Download');
        $this->get(self::BASE.'/reports/'.$order->public_id.'/lab')->assertRedirect(self::BASE.'/reports/'.$order->public_id);
    }

    public function test_lab_and_imaging_reports_and_one_file_with_both(): void
    {
        app(ModuleManager::class)->enable('imaging');
        $usg = $this->service('USG whole abdomen', '1200', ServiceType::Imaging);
        $order = $this->labOrder([$this->cbc, $usg]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));

        $this->asPatient();
        $this->lookup($order);
        $this->get(self::BASE.'/reports/'.$order->public_id)->assertSee('USG whole abdomen')->assertSee('Not ready yet')->assertDontSee('imaging report (PDF)');

        $this->actingAs($this->verifierUser);
        $study = Study::where('order_id', $order->id)->sole();
        app(WriteReport::class)->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal.</p>'], $study->lock_version);

        $this->asPatient();
        $this->lookup($order);
        $this->get(self::BASE.'/reports/'.$order->public_id)
            ->assertSee('Download lab report (PDF)')->assertSee('Download imaging report (PDF)')->assertSee('Download all reports in one file (PDF)');

        foreach (['imaging', 'all'] as $kind) {
            $this->get(self::BASE.'/reports/'.$order->public_id.'/'.$kind)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_the_bill_tells_the_patient_where_to_look_and_the_page_is_gone_when_the_module_is_off(): void
    {
        $order = $this->released();

        $this->signIn($this->ownerUser)->get(self::BASE.'/print/invoice/'.$order->invoice->public_id)
            ->assertSee('See your reports online: alpha.test/reports');

        $this->disableModule('portal');
        $this->signIn($this->ownerUser)->get(self::BASE.'/print/invoice/'.$order->invoice->public_id)->assertDontSee('See your reports online');

        $this->asPatient();
        $this->get(self::BASE.'/reports')->assertNotFound();
    }
}
