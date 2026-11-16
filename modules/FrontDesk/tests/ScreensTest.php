<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Money\Money;
use App\Core\Patients\Patient;
use App\Core\Settings\Settings;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\FrontDesk\Actions\RequestRefund;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\RefundStatus;
use Modules\FrontDesk\Filament\Cash\ListCashSessions;
use Modules\FrontDesk\Filament\Catalog\CreateService;
use Modules\FrontDesk\Filament\FrontDeskSettings;
use Modules\FrontDesk\Filament\Orders\CreateOrder;
use Modules\FrontDesk\Filament\Orders\ViewOrder;
use Modules\FrontDesk\Filament\Refunds\ListRefunds;
use Modules\FrontDesk\Filament\TodayWidget;
use Modules\FrontDesk\Models\CashSession;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Permissions;

final class ScreensTest extends FrontDeskTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_screen_opens_for_the_owner(): void
    {
        $order = $this->order(['500']);
        $payment = $this->pay($order, '200');
        $service = Service::firstOrFail();
        $patient = Patient::firstOrFail();

        foreach ([
            '/', '/patients', '/patients/create', "/patients/{$patient->public_id}/edit",
            '/orders', '/orders/create', "/orders/{$order->public_id}",
            '/dues', '/refunds', '/cash-sessions', '/front-desk/settings',
            '/catalog/services', '/catalog/services/create', "/catalog/services/{$service->public_id}/edit",
            '/catalog/categories', '/catalog/referral-doctors',
            "/print/invoice/{$this->invoice($order)->public_id}", "/print/receipt/{$payment->public_id}",
        ] as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertOk();
        }
    }

    public function test_addresses_use_the_public_id_not_the_row_number(): void
    {
        $order = $this->order(['500']);
        $this->pay($order, '100');
        $patient = Patient::firstOrFail();

        // Links on the lists must point at addresses that open.
        $this->signIn($this->ownerUser)->get(self::BASE.'/orders')->assertSee(self::BASE."/orders/{$order->public_id}", false);
        $this->signIn($this->ownerUser)->get(self::BASE.'/dues')->assertSee(self::BASE."/orders/{$order->public_id}", false);
        $this->signIn($this->ownerUser)->get(self::BASE.'/patients')->assertSee(self::BASE."/patients/{$patient->public_id}/edit", false);
        $this->signIn($this->ownerUser)->get(self::BASE.'/users')->assertSee(self::BASE."/users/{$this->ownerUser->public_id}/edit", false);

        $this->signIn($this->ownerUser)->get(self::BASE."/orders/{$order->id}")->assertNotFound();
        $this->signIn($this->ownerUser)->get(self::BASE."/print/invoice/{$this->invoice($order)->id}")->assertNotFound();
    }

    public function test_the_dashboard_shows_todays_figures(): void
    {
        $order = $this->order(['1500']);
        $this->pay($order, '1000');

        // Dashboard widgets load after the page, so the widget is tested directly.
        Livewire::test(TodayWidget::class)
            ->assertSee('Collected')
            ->assertSee('1,000.00')
            ->assertSee('500.00');

        $this->actingAs($this->staff('lab_technician'));
        $this->assertFalse(TodayWidget::canView());
    }

    public function test_screens_follow_the_roles(): void
    {
        $order = $this->order(['500']);
        $url = fn (string $path): string => self::BASE.$path;

        $receptionist = $this->staff('receptionist');
        foreach (['/patients', '/orders', '/orders/create', "/orders/{$order->public_id}", '/dues', '/cash-sessions', '/refunds'] as $path) {
            $this->signIn($receptionist)->get($url($path))->assertOk();
        }
        foreach (['/catalog/services', '/catalog/categories', '/catalog/referral-doctors', '/front-desk/settings'] as $path) {
            $this->signIn($receptionist)->get($url($path))->assertForbidden();
        }

        $technician = $this->staff('lab_technician');
        $this->signIn($technician)->get($url('/patients'))->assertOk();
        foreach (['/patients/create', '/orders', "/orders/{$order->public_id}", '/dues', '/cash-sessions', "/print/invoice/{$this->invoice($order)->public_id}"] as $path) {
            $this->signIn($technician)->get($url($path))->assertForbidden();
        }
    }

    public function test_print_pages_need_a_signed_in_user(): void
    {
        $order = $this->order(['500']);

        auth()->logout();
        $this->flushSession();

        $this->get(self::BASE."/print/invoice/{$this->invoice($order)->public_id}")->assertRedirect('/login');
    }

    public function test_the_invoice_and_receipt_print_the_right_figures(): void
    {
        app(Settings::class)->set('clinic.address', 'KDA Avenue, Khulna');
        $order = $this->order(['1200', '300'], ['discount_type' => 'amount', 'discount_value' => '100', 'discount_reason' => 'Regular']);
        $payment = $this->pay($order, '900');
        $invoice = $this->invoice($order);

        $this->signIn($this->ownerUser)->get(self::BASE."/print/invoice/{$invoice->public_id}")
            ->assertSee('Alpha Diagnostics')
            ->assertSee('KDA Avenue, Khulna')
            ->assertSee($invoice->invoice_no)
            ->assertSee('Rahim Uddin')
            ->assertSee('Discount: Regular')
            ->assertSeeInOrder(['Total', '1,400.00', 'Paid', '900.00', 'Due', '500.00']);

        $this->signIn($this->ownerUser)->get(self::BASE."/print/receipt/{$payment->public_id}")
            ->assertSee($payment->receipt_no)
            ->assertSeeInOrder(['Received', '900.00', 'Due now', '500.00']);
    }

    public function test_a_patient_is_registered_and_edited_from_the_screen(): void
    {
        Livewire::test(CreatePatient::class)
            ->fillForm(['name' => 'Salma Begum', 'sex' => 'female', 'phone' => '01811-222333'])
            ->call('create')
            ->assertNotified('Not saved');   // neither birth date nor age

        Livewire::test(CreatePatient::class)
            ->fillForm(['name' => 'Salma Begum', 'sex' => 'female', 'age_years' => 42, 'phone' => '01811-222333'])
            ->call('create')
            ->assertHasNoFormErrors();

        $salma = Patient::where('name', 'Salma Begum')->sole();
        $this->assertTrue($salma->dob_is_estimated);
        $this->assertSame('42 Y', $salma->ageLabel());
        $this->assertSame('8801811222333', $salma->phone_normalized);

        Livewire::test(EditPatient::class, ['record' => $salma->public_id])
            ->assertFormSet(['name' => 'Salma Begum', 'age_years' => 42, 'date_of_birth' => null])
            ->fillForm(['address' => 'Boyra, Khulna'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Boyra, Khulna', $salma->fresh()->address);
        $this->assertSame('42 Y', $salma->fresh()->ageLabel(), 'saving again does not shift the age');
    }

    public function test_the_registration_form_warns_about_a_phone_already_in_use(): void
    {
        $existing = $this->patient('Abdul Karim');

        Livewire::test(CreatePatient::class)
            ->fillForm(['phone' => '+880 1711-000000'])
            ->assertSee('Already registered with this phone')
            ->assertSee("Abdul Karim ({$existing->patient_code})");
    }

    public function test_a_service_and_a_package_are_created_from_the_screen(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm(['code' => 'cbc', 'name' => 'Complete blood count', 'type' => 'lab_test', 'price' => '12.345'])
            ->call('create')
            ->assertHasFormErrors(['price']);

        Livewire::test(CreateService::class)
            ->fillForm(['code' => 'cbc', 'name' => 'Complete blood count', 'type' => 'lab_test', 'price' => '400'])
            ->call('create')
            ->assertHasNoFormErrors();

        $cbc = Service::where('code', 'CBC')->sole();
        $this->assertSame('400.00', $cbc->price->toDecimal());

        Livewire::test(CreateService::class)
            ->fillForm(['code' => 'fever', 'name' => 'Fever panel', 'type' => 'package', 'price' => '350.50', 'packageItems' => [$cbc->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Service::where('code', 'FEVER')->sole();
        $this->assertSame([$cbc->id], $package->packageItems()->pluck('fd_services.id')->all());
    }

    public function test_an_order_with_discount_and_part_payment_is_created_from_the_screen(): void
    {
        $patient = $this->patient();
        $cbc = $this->service('CBC', '400');
        $lipid = $this->service('Lipid profile', '600');
        $this->openCash('0');

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'patient_id' => $patient->id,
                'service_ids' => [$cbc->id, $lipid->id],
            ])
            ->assertSee('Subtotal: ৳ 1,000.00')
            ->fillForm([
                'discount_type' => 'percent',
                'discount_value' => '10',
                'discount_reason' => 'Senior citizen',
                'pay_amount' => '500',
                'pay_method' => 'cash',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Order created');

        $order = Order::sole();
        $this->assertSame($patient->id, $order->patient_id);
        $this->assertInvoice($order, '900.00', '500.00', '0.00', '400.00', 'partially_paid');
        $this->assertNotNull(Payment::sole()->cash_session_id);
    }

    public function test_a_refused_payment_does_not_lose_the_order(): void
    {
        $patient = $this->patient();
        $cbc = $this->service('CBC', '400');

        // Cash, but no cash session is open.
        Livewire::test(CreateOrder::class)
            ->fillForm(['patient_id' => $patient->id, 'service_ids' => [$cbc->id], 'pay_amount' => '400', 'pay_method' => 'cash'])
            ->call('create')
            ->assertNotified('Order created, but the payment was not recorded');

        $this->assertInvoice(Order::sole(), '400.00', '0.00', '0.00', '400.00', 'issued');
    }

    public function test_a_refused_order_shows_the_reason_and_creates_nothing(): void
    {
        $patient = $this->patient();
        $cbc = $this->service('CBC', '400');
        $this->actingAs($this->staff('receptionist'));

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'patient_id' => $patient->id, 'service_ids' => [$cbc->id],
                'discount_type' => 'percent', 'discount_value' => '50', 'discount_reason' => 'Friend',
            ])
            ->call('create')
            ->assertNotified('Order not created');

        $this->assertSame(0, Order::count());
    }

    public function test_the_order_screen_takes_a_payment_once_even_if_submitted_twice(): void
    {
        $order = $this->order(['1000']);

        $screen = Livewire::test(ViewOrder::class, ['record' => $order->public_id])
            ->assertSee($order->order_no)
            ->assertActionVisible('pay')
            ->callAction('pay', ['amount' => '400', 'method' => 'bkash', 'reference' => 'TXN1'])
            ->assertNotified('Payment recorded');

        $this->assertInvoice($order, '1000.00', '400.00', '0.00', '600.00', 'partially_paid');

        $screen->callAction('pay', ['amount' => '700', 'method' => 'bkash'])->assertNotified('Payment not recorded');
        $this->assertSame(1, Payment::count());

        $screen->callAction('pay', ['amount' => '600', 'method' => 'bkash'])->assertNotified('Payment recorded');
        $this->assertInvoice($order, '1000.00', '1000.00', '0.00', '0.00', 'paid');

        Livewire::test(ViewOrder::class, ['record' => $order->public_id])->assertActionHidden('pay');
    }

    public function test_tests_are_added_cancelled_and_refunded_from_the_order_screen(): void
    {
        $order = $this->order(['600', '400']);
        $this->pay($order, '1000');
        $extra = $this->service('Blood glucose', '250');
        $screen = fn () => Livewire::test(ViewOrder::class, ['record' => $order->public_id]);

        $screen()->callAction('addTest', ['service_id' => $extra->id])->assertNotified('Test added');
        $this->assertInvoice($order, '1250.00', '1000.00', '0.00', '250.00', 'partially_paid');

        $screen()->callAction('cancelTest', ['item_id' => $order->items[1]->id, 'reason' => 'Not needed'])->assertNotified('Test cancelled');
        $this->assertSame(ItemStatus::Cancelled, $order->items()->get()[1]->status);
        $this->assertInvoice($order, '850.00', '1000.00', '0.00', '-150.00', 'paid');

        $screen()->assertSee('To refund')
            ->assertActionHidden('cancelOrder')
            ->callAction('refund', ['amount' => '150', 'method' => 'bkash', 'reason' => 'Test cancelled'])
            ->assertNotified('Refund requested');

        $this->assertSame(RefundStatus::Pending, $this->invoice($order)->refunds()->sole()->status);
    }

    public function test_an_unpaid_order_is_cancelled_from_the_order_screen(): void
    {
        $order = $this->order(['600']);

        Livewire::test(ViewOrder::class, ['record' => $order->public_id])
            ->callAction('cancelOrder', ['reason' => 'Duplicate'])
            ->assertNotified('Order cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        Livewire::test(ViewOrder::class, ['record' => $order->public_id])
            ->assertActionHidden('pay')
            ->assertActionHidden('addTest')
            ->assertActionHidden('cancelOrder');
    }

    public function test_actions_are_hidden_from_users_who_may_not_use_them(): void
    {
        $order = $this->order(['600']);
        $this->pay($order, '100');

        $this->actingAs($this->staff('receptionist'));

        Livewire::test(ViewOrder::class, ['record' => $order->public_id])
            ->assertActionVisible('pay')
            ->assertActionVisible('refund')
            ->assertActionHidden('addTest')
            ->assertActionHidden('cancelTest');

        $this->actingAs($this->staff('lab_technician'));
        Livewire::test(ViewOrder::class, ['record' => $order->public_id])->assertForbidden();
    }

    public function test_refunds_are_approved_and_rejected_from_the_refund_list(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '1000');
        $request = fn (string $amount) => app(RequestRefund::class)->handle($this->invoice($order), Money::fromDecimal($amount), PaymentMethod::Bkash, 'Overcharged');
        $first = $request('100');
        $second = $request('50');

        // The requester cannot approve their own request.
        Livewire::test(ListRefunds::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('approve', $first)
            ->assertNotified('Not done');
        $this->assertSame(RefundStatus::Pending, $first->fresh()->status);

        $this->actingAs($this->staff('accountant'));

        Livewire::test(ListRefunds::class)
            ->callTableAction('approve', $first)
            ->assertNotified('Refund approved')
            ->callTableAction('reject', $second, ['note' => 'Already settled'])
            ->assertNotified('Refund rejected');

        $this->assertSame(RefundStatus::Approved, $first->fresh()->status);
        $this->assertSame(RefundStatus::Rejected, $second->fresh()->status);
        $this->assertInvoice($order, '1000.00', '1000.00', '100.00', '100.00', 'partially_paid');

        $this->actingAs($this->staff('receptionist'));
        Livewire::test(ListRefunds::class)->assertTableActionHidden('approve', $request('10'));
    }

    public function test_a_cash_session_is_opened_and_closed_from_the_screen(): void
    {
        Livewire::test(ListCashSessions::class)
            ->assertSee('You have no open cash session')
            ->assertActionVisible('open')
            ->assertActionHidden('close')
            ->callAction('open', ['opening_float' => '500'])
            ->assertNotified('Cash session opened');

        $this->pay($this->order(['300']), '300', PaymentMethod::Cash);

        Livewire::test(ListCashSessions::class)
            ->assertSee('should hold ৳ 800.00 now')
            ->assertActionHidden('open')
            ->callAction('close', ['counted_cash' => '790'])
            ->assertNotified('Not done')
            ->callAction('close', ['counted_cash' => '790', 'notes' => 'Ten short'])
            ->assertNotified('Cash session closed');

        $this->assertSame('-10.00', CashSession::sole()->difference->toDecimal());
    }

    public function test_staff_see_only_their_own_cash_sessions(): void
    {
        $mine = $this->openCash('100');

        $receptionist = $this->staff('receptionist');
        $this->actingAs($receptionist);
        $theirs = $this->openCash('50');

        Livewire::test(ListCashSessions::class)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);

        $this->actingAs($this->staff('accountant'));
        Livewire::test(ListCashSessions::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_the_refund_approval_rule_is_changed_from_settings(): void
    {
        Livewire::test(FrontDeskSettings::class)
            ->assertFormSet(['refund_requires_approval' => true])
            ->fillForm(['refund_requires_approval' => false])
            ->call('save')
            ->assertNotified('Settings saved');

        $this->assertFalse(app(Settings::class)->get(Permissions::SETTING_REFUND_APPROVAL));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated'], 'clinic');

        $this->actingAs($this->staff('receptionist'));
        Livewire::test(FrontDeskSettings::class)->assertForbidden();
    }

    public function test_switching_the_module_off_hides_all_of_its_screens(): void
    {
        $order = $this->order(['500']);
        $this->disableModule('frontdesk');

        $this->signIn($this->ownerUser)->get(self::BASE.'/')->assertOk()->assertDontSee('Cash sessions');
        $this->assertFalse(TodayWidget::canView());

        foreach (['/orders', "/orders/{$order->public_id}", '/dues', '/refunds', '/cash-sessions', '/catalog/services', '/front-desk/settings'] as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertForbidden();
        }

        $this->signIn($this->ownerUser)->get(self::BASE."/print/invoice/{$this->invoice($order)->public_id}")->assertNotFound();

        // Patients belong to the core and stay.
        $this->signIn($this->ownerUser)->get(self::BASE.'/patients')->assertOk();
    }
}
