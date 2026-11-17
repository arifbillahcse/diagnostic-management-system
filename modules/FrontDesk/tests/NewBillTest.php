<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Patients\Patient;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Filament\NewBill;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Payment;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;

final class NewBillTest extends FrontDeskTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function test(): Testable
    {
        return Livewire::test(NewBill::class);
    }

    private function catalog(): array
    {
        $cbc = $this->service('CBC', '400');
        $cbc->update(['code' => 'CBC']);
        $lipid = $this->service('Lipid profile', '1200');
        $lipid->update(['code' => 'LIPID']);
        $urine = $this->service('Urine R/E', '150');
        $urine->update(['code' => 'URINE']);

        return [$cbc, $lipid, $urine];
    }

    private function fillNewPatient($component, array $over = [])
    {
        return $component->set('mobile', $over['mobile'] ?? '01911-556677')
            ->set('name', $over['name'] ?? 'Md. Jahidul Islam')
            ->set('age', $over['age'] ?? '28')
            ->set('ageUnit', $over['ageUnit'] ?? 'Y')
            ->set('sex', $over['sex'] ?? 'male')
            ->set('address', $over['address'] ?? 'Boyra, Khulna');
    }

    public function test_a_new_patient_and_the_bill_are_made_in_one_save(): void
    {
        $this->catalog();
        $this->openCash();
        $bill = $this->fillNewPatient($this->test())
            ->call('addByCode', 'cbc')
            ->call('addByCode', 'LIPID')
            ->call('addByCode', 'URINE');

        $bill->assertSet('paid', '1750.00');

        $bill->call('save')->assertHasNoErrors()->assertNotified('Bill INV-MAIN-'.now('Asia/Dhaka')->format('ym').'-00001 saved');

        $patient = Patient::sole();
        $this->assertSame('Md. Jahidul Islam', $patient->name);
        $this->assertSame('8801911556677', $patient->phone_normalized);
        $this->assertTrue($patient->dob_is_estimated);
        $this->assertSame('28 Y', $patient->ageLabel());

        $order = Order::sole();
        $this->assertSame($patient->id, $order->patient_id);
        $this->assertNotNull($order->delivery_at);
        $this->assertInvoice($order, '1750.00', '1750.00', '0.00', '0.00', 'paid');
        $this->assertSame('cash', Payment::sole()->method->value);

        // The screen is blank again and shows the bill it just saved.
        $bill->assertSet('name', '')->assertSet('serviceIds', [])->assertSet('patientId', null)->assertSet('paid', '')
            ->assertSee('Saved INV-MAIN-')->assertSee('Print invoice')->assertSee('Office copy');
    }

    public function test_the_age_unit_makes_a_baby_or_an_adult(): void
    {
        $this->catalog();

        $this->fillNewPatient($this->test(), ['age' => '3', 'ageUnit' => 'M', 'mobile' => '01711000001'])->call('addByCode', 'CBC')->call('save');
        $this->fillNewPatient($this->test(), ['age' => '12', 'ageUnit' => 'D', 'mobile' => '01711000002', 'name' => 'Baby'])->call('addByCode', 'CBC')->call('save');

        $this->assertSame(['3 M', '12 D'], Patient::orderBy('id')->get()->map->ageLabel()->all());
    }

    public function test_the_two_discount_boxes_follow_each_other_and_the_bill_uses_the_one_typed_last(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->call('addByCode', 'LIPID')->set('payMethod', 'bkash');

        // "Paid" follows the net payable, so nothing is due and nothing is returned.
        $this->assertSame('0.00', $bill->instance()->figures()['due']->toDecimal());
        $this->assertSame('0.00', $bill->instance()->figures()['change']->toDecimal());
        $this->assertSame('1600.00', $bill->instance()->figures()['paid']->toDecimal());

        $bill->set('discountPercent', '10')->assertSet('discountAmount', '160.00')->assertSet('discountSource', 'percent');
        $figures = $bill->instance()->figures();
        $this->assertSame('1600.00', $figures['subtotal']->toDecimal());
        $this->assertSame('160.00', $figures['discount']->toDecimal());
        $this->assertSame('1440.00', $figures['net']->toDecimal());
        $bill->assertSet('paid', '1440.00');

        $bill->set('discountAmount', '200')->assertSet('discountPercent', '12.5')->assertSet('discountSource', 'amount');
        $this->assertSame('1400.00', $bill->instance()->figures()['net']->toDecimal());
        $bill->assertSet('paid', '1400.00');

        // More tests: a percentage follows the new total, a fixed amount stays fixed.
        $bill->call('addByCode', 'URINE')->assertSet('discountAmount', '200')->assertSet('discountPercent', '11.43');
        $bill->set('discountPercent', '10')->call('addByCode', 'URINE'); // already on the bill: nothing changes
        $bill->assertSet('discountAmount', '175.00');

        // Clearing a box clears both.
        $bill->set('discountPercent', '')->assertSet('discountAmount', '')->assertSet('discountSource', '');
        $this->assertSame('1750.00', $bill->instance()->figures()['net']->toDecimal());
    }

    public function test_the_discount_needs_a_reason_and_is_made_from_the_box_typed_last(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->call('addByCode', 'LIPID')
            ->set('payMethod', 'bkash')->set('discountPercent', '10');

        $bill->assertSee('Reason');
        $bill->call('save')->assertNotified('Bill not saved');
        $this->assertSame(0, Order::count());

        $bill->set('discountReason', 'Regular patient')->call('save')->assertHasNoErrors();
        $this->assertInvoice(Order::sole(), '1440.00', '1440.00', '0.00', '0.00', 'paid');
        $this->assertSame('160.00', Invoice::sole()->discount_total->toDecimal());

        $amount = $this->fillNewPatient($this->test(), ['mobile' => '01711000005'])->call('addByCode', 'LIPID')
            ->set('payMethod', 'bkash')->set('discountAmount', '100')->set('discountReason', 'Staff');
        $amount->call('save');
        $this->assertSame('100.00', Invoice::orderByDesc('id')->first()->discount_total->toDecimal());
    }

    public function test_a_discount_larger_than_the_bill_is_flagged_and_refused(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'URINE')->set('discountAmount', '500')->set('discountReason', 'x');
        $this->assertNotNull($bill->instance()->discountProblem());
        $bill->assertSee('cannot be more than');
        $bill->call('save')->assertNotified('Bill not saved');
        $this->assertSame(0, Order::count());
    }

    public function test_a_discount_over_the_limit_is_refused_before_the_patient_is_registered(): void
    {
        $this->catalog();
        $this->actingAs($this->staff('receptionist')); // may give up to 10 percent

        $bill = $this->fillNewPatient($this->test())
            ->call('addByCode', 'LIPID')->set('payMethod', 'bkash')
            ->set('discountPercent', '50')->set('discountReason', 'Friend');

        $bill->call('save')->assertNotified('Bill not saved');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Patient::count(), 'a refused discount leaves no patient behind');

        $bill->set('discountPercent', '10')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, Order::count());
    }

    public function test_when_the_bill_is_refused_after_the_patient_is_registered_the_next_try_reuses_them(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'LIPID')->set('payMethod', 'bkash')->set('deliveryAt', '2020-01-01T10:00');

        $bill->call('save')->assertNotified('Bill not saved');
        $this->assertSame(1, Patient::count(), 'the patient was registered before the bill was refused');
        $this->assertSame(0, Order::count());
        $bill->assertSee('Change patient'); // the patient is now chosen, not typed

        $bill->set('deliveryAt', '')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Patient::count(), 'the second try reused the registered patient');
        $this->assertSame(1, Order::count());
    }

    public function test_paying_less_leaves_a_due_and_paying_more_gives_change(): void
    {
        $this->catalog();

        $part = $this->fillNewPatient($this->test())->call('addByCode', 'LIPID')->set('payMethod', 'bkash')->set('payReference', 'TX123')->set('paid', '500');
        $figures = $part->instance()->figures();
        $this->assertSame('700.00', $figures['due']->toDecimal());
        $part->call('save');
        $this->assertInvoice(Order::sole(), '1200.00', '500.00', '0.00', '700.00', 'partially_paid');
        $this->assertSame('TX123', Payment::sole()->reference);

        $change = $this->fillNewPatient($this->test(), ['mobile' => '01711000009'])->call('addByCode', 'URINE')->set('payMethod', 'bkash')->set('paid', '200');
        $this->assertSame('50.00', $change->instance()->figures()['change']->toDecimal());
        $change->call('save')->assertSee('Return 50.00');

        $second = Invoice::orderByDesc('id')->first();
        $this->assertSame('150.00', $second->paid_total->toDecimal(), 'only what was owed is recorded; the rest is change');
        $this->assertSame('0.00', $second->due_amount->toDecimal());
    }

    public function test_the_exact_amount_link_goes_back_to_the_net_payable(): void
    {
        $this->catalog();

        $bill = $this->test()->call('addByCode', 'LIPID')->set('paid', '300');
        $this->assertSame('900.00', $bill->instance()->figures()['due']->toDecimal());

        $bill->call('payExact')->assertSet('paid', '1200.00')->assertSet('paidTouched', false);
        $this->assertSame('0.00', $bill->instance()->figures()['due']->toDecimal());

        // And it follows the bill again afterwards.
        $bill->call('addByCode', 'CBC')->assertSet('paid', '1600.00');
    }

    public function test_an_unpaid_bill_can_be_saved_with_nothing_paid(): void
    {
        $this->catalog();

        $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->set('paid', '')->set('paidTouched', true)->call('save');

        $this->assertInvoice(Order::sole(), '400.00', '0.00', '0.00', '400.00', 'issued');
        $this->assertSame(0, Payment::count());
    }

    public function test_cash_without_an_open_session_saves_the_bill_and_says_the_payment_was_not_taken(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'CBC');
        $this->assertTrue($bill->instance()->needsCashSession());
        $bill->assertSee('no open cash session');

        $bill->call('save')->assertNotified('Bill saved, but the payment was not recorded')->assertSee('Payment not recorded');

        $this->assertSame(1, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertInvoice(Order::sole(), '400.00', '0.00', '0.00', '400.00', 'issued');
    }

    public function test_supplies_appear_on_the_screen_and_are_billed_once(): void
    {
        [$cbc, $lipid] = $this->catalog();
        $needle = $this->service('Needle', '5', ServiceType::Other);
        $tube = $this->service('EDTA tube', '10', ServiceType::Other);
        $cbc->addons()->sync([$needle->id, $tube->id]);
        $lipid->addons()->sync([$needle->id]);

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->call('addByCode', 'LIPID');

        $lines = $bill->instance()->lines();
        $this->assertSame(['CBC', 'LIPID', $needle->code, $tube->code], array_column($lines, 'code'));
        $this->assertSame([false, false, true, true], array_column($lines, 'auto'));
        $bill->assertSee('added automatically')->assertSet('paid', '1615.00');

        $bill->set('payMethod', 'bkash')->call('save');
        $this->assertInvoice(Order::sole(), '1615.00', '1615.00', '0.00', '0.00', 'paid');
    }

    public function test_codes_are_found_in_any_case_and_a_test_is_added_once(): void
    {
        $this->catalog();
        $inactive = $this->service('Old test', '99');
        $inactive->update(['code' => 'OLD', 'is_active' => false]);

        $bill = $this->test()->call('addByCode', ' cbc ')->call('addByCode', 'CBC')->assertNotified('CBC is already on this bill'.'');
        $bill->assertSet('serviceIds', [Service::where('code', 'CBC')->value('id')]);

        $bill->call('addByCode', 'NOPE')->assertNotified('No test with code NOPE');
        $bill->call('addByCode', 'OLD')->assertNotified('No test with code OLD');
        $this->assertCount(1, $bill->instance()->lines());

        $bill->call('removeService', Service::where('code', 'CBC')->value('id'))->assertSet('serviceIds', [])->assertSet('paid', '');
    }

    public function test_a_test_picked_from_the_list_is_added(): void
    {
        [$cbc] = $this->catalog();

        $this->test()->call('addById', $cbc->id)->assertSet('serviceIds', [$cbc->id])->assertSee('CBC');
    }

    public function test_an_existing_patient_is_found_by_id_and_not_registered_again(): void
    {
        $this->catalog();
        $existing = $this->patient('Rahim Uddin');

        $byCode = $this->test()->set('patientSearch', $existing->patient_code)->call('findPatient');
        $byCode->assertSet('patientId', $existing->id)->assertSet('name', 'Rahim Uddin')->assertSet('mobile', '01711000000')->assertSee('Change patient')
            ->assertSet('patientSearch', '')->assertSet('searchResults', []);
        $byCode->call('addByCode', 'CBC')->set('payMethod', 'bkash')->call('save')->assertHasNoErrors();

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Order::sole()->patient_id);
    }

    public function test_the_find_box_lists_patients_by_id_name_or_mobile_as_you_type(): void
    {
        $rahim = $this->patient('Rahim Uddin');
        $salma = $this->patient('Salma Akter');
        $salma->forceFill(['phone' => '01822-334455', 'phone_normalized' => '8801822334455', 'sex' => 'female'])->save();

        $bill = $this->test();

        // Too short to search.
        $bill->set('patientSearch', 'R')->assertSet('searchResults', []);

        // By name: the ID, the name, and the details to tell people apart.
        $bill->set('patientSearch', 'Rah')->assertSet('searchResults', [
            ['id' => $rahim->id, 'code' => $rahim->patient_code, 'name' => 'Rahim Uddin', 'age' => $rahim->ageLabel(), 'sex' => 'Male', 'phone' => '01711000000'],
        ])->assertSee($rahim->patient_code)->assertSee('Rahim Uddin');

        // By ID, by the start of an ID shared by both, and by mobile.
        $bill->set('patientSearch', $salma->patient_code)->assertSet('searchResults.0.name', 'Salma Akter');
        $bill->set('patientSearch', 'P-T')->assertCount('searchResults', 2);
        $bill->set('patientSearch', '01822')->assertSet('searchResults.0.name', 'Salma Akter')->assertSet('searchResults.0.sex', 'Female');

        // Picking one fills the patient in and clears the box.
        $bill->call('usePatient', $salma->id)->assertSet('patientId', $salma->id)->assertSet('patientSearch', '')->assertSet('searchResults', []);

        $bill->call('clearPatient')->assertSet('patientId', null)->assertSet('name', '');
    }

    public function test_the_find_box_shows_at_most_eight_and_nothing_to_those_who_may_not_look(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->patient("Karim {$i}");
        }

        $this->test()->set('patientSearch', 'Karim')->assertCount('searchResults', 8);

        $this->actingAs($this->staff('lab_technician'));
        // A technician has no front desk permissions, so cannot even open the screen.
        Livewire::test(NewBill::class)->assertForbidden();
    }

    public function test_nothing_found_starts_a_new_patient_with_what_was_typed(): void
    {
        $this->catalog();

        $number = $this->test()->set('patientSearch', '01733-445566')->assertSee('No registered patient matches')->call('findPatient');
        $number->assertSet('mobile', '01733-445566')->assertSet('name', '')->assertSet('patientSearch', '')->assertSet('patientId', null)->assertDispatched('bill-focus', target: 'name');

        $name = $this->test()->set('patientSearch', 'Nasrin Sultana')->call('findPatient');
        $name->assertSet('name', 'Nasrin Sultana')->assertSet('mobile', '')->assertDispatched('bill-focus', target: 'mobile');

        $this->fillNewPatient($number, ['mobile' => '01733-445566', 'name' => 'Nasrin Sultana'])->call('addByCode', 'CBC')->set('payMethod', 'bkash')->call('save')->assertHasNoErrors();
        $this->assertSame('Nasrin Sultana', Patient::sole()->name);
    }

    public function test_the_mobile_box_offers_the_people_already_registered_with_that_number(): void
    {
        $existing = $this->patient('Rahim Uddin');

        $byMobile = $this->test()->set('mobile', '01711-000000');
        $byMobile->assertSee('P-T-0001')->assertSee('Rahim Uddin');
        $this->assertSame('Rahim Uddin', $byMobile->get('suggestions')[0]['name']);

        $byMobile->call('usePatient', $existing->id)->assertSet('patientId', $existing->id)->assertSet('suggestions', []);

        $this->test()->set('mobile', '01999999999')->assertSet('suggestions', []);
        $this->test()->set('mobile', '0171')->assertSet('suggestions', []);
    }

    public function test_an_existing_patients_details_are_not_changed_from_this_screen(): void
    {
        $this->catalog();
        $existing = $this->patient('Rahim Uddin');

        $this->test()->call('usePatient', $existing->id)
            ->set('name', 'Someone Else')->set('address', 'Elsewhere')
            ->call('addByCode', 'CBC')->set('payMethod', 'bkash')->call('save');

        $this->assertSame('Rahim Uddin', $existing->fresh()->name);
    }

    public function test_the_browser_cannot_choose_a_patient_it_may_not_see(): void
    {
        $this->catalog();
        $existing = $this->patient('Rahim Uddin');
        $noView = $this->staff('receptionist');
        $noView->roles()->detach();
        $this->actingAs($noView);

        // Not even allowed to open the screen without the permission to make orders.
        Livewire::test(NewBill::class)->assertForbidden();

        $this->actingAs($this->ownerUser);
        $this->test()->assertOk();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->test()->set('patientId', $existing->id);
    }

    public function test_pressing_save_twice_makes_one_bill(): void
    {
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->set('payMethod', 'bkash');
        $token = $bill->get('billToken');

        $bill->call('save');
        $this->fillNewPatient($bill->set('billToken', $token))->call('addByCode', 'CBC')->set('payMethod', 'bkash')->call('save');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_missing_details_stop_the_save_and_create_nothing(): void
    {
        $this->catalog();

        $this->test()->call('save')->assertHasErrors(['serviceIds', 'name', 'mobile', 'age']);

        $this->test()->set('name', 'X')->set('mobile', '01711000000')->set('age', '200')->call('addByCode', 'CBC')->call('save')->assertHasErrors(['age']);
        $this->test()->set('name', 'X')->set('mobile', '01711000000')->set('age', 'abc')->call('addByCode', 'CBC')->call('save')->assertHasErrors(['age']);
        $this->test()->set('name', 'X')->set('mobile', '01711000000')->set('age', '30')->call('addByCode', 'CBC')->set('discountAmount', '12abc')->call('save')->assertHasErrors(['discountAmount']);
        $this->test()->set('name', 'X')->set('mobile', '01711000000')->set('age', '30')->call('addByCode', 'CBC')->set('paid', '12abc')->call('save')->assertHasErrors(['paid']);

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Order::count());
    }

    public function test_the_delivery_time_has_a_default_and_can_be_changed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:38', 'Asia/Dhaka'));
        $this->catalog();

        $bill = $this->fillNewPatient($this->test())->assertSet('deliveryAt', '2026-11-18T19:00');
        $bill->call('addByCode', 'CBC')->set('payMethod', 'bkash')->call('save');
        $this->assertSame('2026-11-18 19:00', Order::sole()->delivery_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));

        $bill->set('mobile', '01711000007')->set('name', 'Y')->set('age', '40')->call('addByCode', 'CBC')->set('payMethod', 'bkash')
            ->set('deliveryAt', '2026-11-17T17:00')->call('save');
        $this->assertSame('2026-11-17 17:00', Order::orderByDesc('id')->first()->delivery_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));
    }

    public function test_remarks_and_doctor_reach_the_invoice(): void
    {
        $this->catalog();
        $doctor = ReferralDoctor::create(['code' => '003', 'name' => 'Dr. Yeasmin Jahan']);

        $this->fillNewPatient($this->test())->call('addByCode', 'CBC')->set('doctorId', $doctor->id)->set('notes', 'C/O Akbor Sir')->set('payMethod', 'bkash')->call('save');

        $order = Order::sole();
        $this->assertSame($doctor->id, $order->referral_doctor_id);
        $this->assertSame('C/O Akbor Sir', $order->notes);

        $this->test()->assertSee('003 · Dr. Yeasmin Jahan');
    }

    public function test_the_page_opens_for_front_desk_staff_only(): void
    {
        $this->signIn($this->ownerUser)->get(self::BASE.'/new-bill')->assertOk()->assertSee('Tests and services')->assertSee('Bill summary')->assertSee('Save bill');
        $this->signIn($this->staff('receptionist'))->get(self::BASE.'/new-bill')->assertOk();
        $this->signIn($this->staff('lab_technician'))->get(self::BASE.'/new-bill')->assertForbidden();

        $this->actingAs($this->ownerUser);
        $this->disableModule('frontdesk');
        $this->signIn($this->ownerUser)->get(self::BASE.'/new-bill')->assertForbidden();
    }

    public function test_the_order_list_points_to_the_new_screen(): void
    {
        $this->signIn($this->ownerUser)->get(self::BASE.'/orders')->assertOk()->assertSee(self::BASE.'/new-bill', false);
    }

    public function test_payment_method_options_match_the_clinics_methods(): void
    {
        $this->catalog();

        $this->test()->assertSee(PaymentMethod::Bkash->label())->assertSee(PaymentMethod::Cash->label());
    }
}
