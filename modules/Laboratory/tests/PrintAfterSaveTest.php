<?php

declare(strict_types=1);

namespace Modules\Laboratory\Tests;

use App\Core\Settings\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\FrontDesk\Filament\NewBill;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Permissions;
use Modules\Laboratory\Enums\SampleStatus;
use Modules\Laboratory\Models\Sample;

final class PrintAfterSaveTest extends LabTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->cbc->update(['code' => 'CBC']);
        $this->urine->update(['code' => 'URINE']);
    }

    private function bill(array $codes = ['CBC', 'URINE'])
    {
        $bill = Livewire::test(NewBill::class)
            ->set('mobile', '01911556677')->set('name', 'Md. Jahidul Islam')->set('age', '28')->set('sex', 'male')
            ->set('payMethod', 'bkash');

        foreach ($codes as $code) {
            $bill->call('addByCode', $code);
        }

        return $bill;
    }

    public function test_after_saving_the_print_options_show_the_invoice_and_the_sample_labels(): void
    {
        $bill = $this->bill()->call('save')->assertHasNoErrors();

        $order = Order::sole();
        $invoice = Invoice::sole();

        $bill->assertSet('printPanel', true)
            ->assertSee("Bill {$invoice->invoice_no} saved")
            ->assertSee('Print invoice')
            ->assertSee('patient + office copy')
            ->assertSee('Print sample labels (3)')
            ->assertSee(route('frontdesk.print.invoice', $invoice->public_id).'?copy=both', false)
            ->assertSee(route('laboratory.print.order-labels', $order->public_id), false);

        // The form is blank behind it, ready for the next patient.
        $bill->assertSet('name', '')->assertSet('serviceIds', []);

        $bill->call('closePrint')->assertSet('printPanel', false)->assertSee('Saved '.$invoice->invoice_no);
    }

    public function test_the_label_count_is_what_will_be_printed(): void
    {
        $this->urineCup->update(['label_copies' => 3]); // blood count tube: 2 labels (set up in LabTestCase); urine cup: 3

        $this->bill()->call('save')->assertSee('Print sample labels (5)');
    }

    public function test_the_clinic_can_print_at_once_or_not_at_all(): void
    {
        $settings = app(Settings::class);
        $settings->set(Permissions::SETTING_AFTER_SAVE, 'invoice');

        $bill = $this->bill(['CBC'])->call('save');
        $invoice = Invoice::sole();
        $bill->assertSet('printPanel', false)->assertDispatched('bill-print', url: route('frontdesk.print.invoice', $invoice->public_id).'?copy=both');

        $settings->set(Permissions::SETTING_AFTER_SAVE, 'none');
        $none = Livewire::test(NewBill::class)
            ->set('mobile', '01711000001')->set('name', 'Second')->set('age', '40')->set('payMethod', 'bkash')->call('addByCode', 'CBC')->call('save');
        $none->assertSet('printPanel', false)->assertNotDispatched('bill-print')->assertSee('Print invoice'); // the strip keeps the links
        $this->assertSame(2, Order::count());
    }

    public function test_the_invoice_prints_one_copy_or_both_and_can_start_printing_by_itself(): void
    {
        $this->bill(['CBC'])->call('save');
        $invoice = Invoice::sole();
        $url = self::BASE."/print/invoice/{$invoice->public_id}";

        $one = $this->signIn($this->ownerUser)->get($url)->assertOk();
        $this->assertSame(1, substr_count($one->getContent(), '(Patient copy)'));
        $this->assertStringNotContainsString('(Office copy)', $one->getContent());
        $this->assertStringNotContainsString('window.print()', str_replace('onclick="window.print()"', '', $one->getContent()));

        $both = $this->signIn($this->ownerUser)->get($url.'?copy=both')->assertOk();
        $this->assertSame(1, substr_count($both->getContent(), '(Patient copy)'));
        $this->assertSame(1, substr_count($both->getContent(), '(Office copy)'));
        $this->assertSame(2, substr_count($both->getContent(), $invoice->invoice_no.'</strong>'), 'the bill number is printed on both pages');

        $this->signIn($this->ownerUser)->get($url.'?copy=office')->assertSee('(Office copy)')->assertDontSee('(Patient copy)');
        $this->signIn($this->ownerUser)->get($url.'?copy=both&autoprint=1')->assertSee('window.print()', false);
    }

    public function test_one_sheet_holds_every_label_of_the_order(): void
    {
        $this->bill()->call('save');
        $order = Order::sole();
        $page = $this->signIn($this->ownerUser)->get(self::BASE."/print/order-labels/{$order->public_id}")->assertOk();

        $accessions = Sample::where('order_id', $order->id)->pluck('accession_no');
        $this->assertCount(2, $accessions);

        foreach ($accessions as $accession) {
            $page->assertSee($accession);
        }

        $page->assertSee('Md. Jahidul Islam')->assertSee('Print 3 labels');
        $this->assertSame(3, substr_count($page->getContent(), 'class="label"'));
    }

    public function test_labels_leave_out_cancelled_and_rejected_samples_and_follow_the_permission(): void
    {
        $this->bill()->call('save');
        $order = Order::sole();

        Sample::where('order_id', $order->id)->orderBy('id')->first()->forceFill(['status' => SampleStatus::Rejected])->save();
        $page = $this->signIn($this->ownerUser)->get(self::BASE."/print/order-labels/{$order->public_id}")->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'class="label"'));

        $this->signIn($this->userWithRoles())->get(self::BASE."/print/order-labels/{$order->public_id}")->assertForbidden();
        $this->signIn($this->staff('receptionist'))->get(self::BASE."/print/order-labels/{$order->public_id}")->assertOk();

        auth()->logout();
        $this->flushSession();
        $this->get(self::BASE."/print/order-labels/{$order->public_id}")->assertRedirect('/login');
    }

    public function test_no_label_option_without_the_laboratory_module_or_samples(): void
    {
        $plain = $this->service('Consultation', '300');
        $plain->update(['code' => 'CONSULT']);

        $this->bill(['CONSULT'])->call('save')->assertSet('printPanel', true)->assertSee('Print invoice')->assertDontSee('Print sample labels');

        $this->bill(['CBC'])->set('mobile', '01711000002')->call('save')->assertSee('Print sample labels');

        $this->disableModule('reports');
        $this->disableModule('laboratory');
        Livewire::test(NewBill::class)
            ->set('mobile', '01711000003')->set('name', 'Third')->set('age', '40')->set('payMethod', 'bkash')->call('addByCode', 'CONSULT')->call('save')
            ->assertDontSee('Print sample labels');
    }
}
