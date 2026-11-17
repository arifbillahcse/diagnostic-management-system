<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Tests;

use App\Core\Settings\Settings;
use Carbon\CarbonImmutable;
use Modules\FrontDesk\Actions\PlaceOrder;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\InvoiceLine;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Models\ServiceCategory;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;

final class InvoiceLayoutTest extends FrontDeskTestCase
{
    private const BASE = 'http://alpha.test';

    /** @return array<string, Service> */
    private function supplies(): array
    {
        $supplies = [];

        foreach (['needle' => '5', 'edta' => '10', 'glucose' => '10'] as $name => $price) {
            $supplies[$name] = $this->service(ucfirst($name).' tube', $price, ServiceType::Other);
        }

        return $supplies;
    }

    private function place(array $services, array $options = [])
    {
        return app(PlaceOrder::class)->handle($this->patient(), $this->branch, array_map(fn ($s) => $s->id, $services), $options);
    }

    public function test_supplies_are_added_once_per_order_however_many_tests_need_them(): void
    {
        ['needle' => $needle, 'edta' => $edta, 'glucose' => $glucose] = $this->supplies();
        $cbc = $this->service('CBC', '400');
        $hba1c = $this->service('HbA1c', '1000');
        $fbs = $this->service('FBS', '150');
        $cbc->addons()->sync([$needle->id, $edta->id]);
        $hba1c->addons()->sync([$needle->id, $edta->id]);
        $fbs->addons()->sync([$needle->id, $glucose->id]);

        $order = $this->place([$cbc, $hba1c, $fbs]);
        $descriptions = $this->invoice($order)->lines()->pluck('description')->all();

        $this->assertSame(['CBC', 'HbA1c', 'FBS', $needle->name, $edta->name, $glucose->name], $descriptions);
        $this->assertInvoice($order, '1575.00', '0.00', '0.00', '1575.00', 'issued');
    }

    public function test_a_supply_chosen_by_hand_is_not_added_a_second_time(): void
    {
        ['needle' => $needle] = $this->supplies();
        $cbc = $this->service('CBC', '400');
        $cbc->addons()->sync([$needle->id]);

        $order = $this->place([$cbc, $needle]);

        $this->assertSame(1, $this->invoice($order)->lines()->where('description', $needle->name)->count());
        $this->assertInvoice($order, '405.00', '0.00', '0.00', '405.00', 'issued');
    }

    public function test_the_supplies_of_the_tests_inside_a_package_are_added(): void
    {
        ['needle' => $needle, 'edta' => $edta] = $this->supplies();
        $cbc = $this->service('CBC', '400');
        $cbc->addons()->sync([$needle->id, $edta->id]);
        $package = $this->package('Health check', '1000', $cbc);

        $order = $this->place([$package]);

        $this->assertSame(['Health check', $needle->name, $edta->name], $this->invoice($order)->lines()->pluck('description')->all());
        $this->assertInvoice($order, '1015.00', '0.00', '0.00', '1015.00', 'issued');
    }

    public function test_an_inactive_supply_is_not_added_and_a_discount_covers_the_supplies(): void
    {
        ['needle' => $needle, 'edta' => $edta] = $this->supplies();
        $edta->update(['is_active' => false]);
        $cbc = $this->service('CBC', '400');
        $cbc->addons()->sync([$needle->id, $edta->id]);

        $order = $this->place([$cbc], ['discount_type' => 'amount', 'discount_value' => '5', 'discount_reason' => 'Regular']);

        $this->assertSame(['CBC', $needle->name, 'Discount: Regular'], $this->invoice($order)->lines()->pluck('description')->all());
        $this->assertInvoice($order, '400.00', '0.00', '0.00', '400.00', 'issued');
        $this->assertSame('405.00', $this->invoice($order)->subtotal->toDecimal());
    }

    public function test_the_report_is_promised_at_the_clinics_usual_time_unless_staff_choose_another(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:40', 'Asia/Dhaka'));
        $cbc = $this->service('CBC', '400');

        $usual = $this->place([$cbc]);
        $this->assertSame('2026-11-14 19:00', $usual->delivery_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));

        app(Settings::class)->set(Permissions::SETTING_DELIVERY_DAYS, 0);
        app(Settings::class)->set(Permissions::SETTING_DELIVERY_TIME, '17:30');
        $sameDay = $this->place([$this->service('RBS', '150')]);
        $this->assertSame('2026-11-13 17:30', $sameDay->delivery_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));

        $chosen = $this->place([$this->service('USG', '700')], ['delivery_at' => '2026-11-13 12:15']);
        $this->assertSame('2026-11-13 12:15', $chosen->delivery_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));
    }

    public function test_a_delivery_time_before_the_order_or_not_a_time_is_refused(): void
    {
        $cbc = $this->service('CBC', '400');

        foreach (['2020-01-01 10:00', 'tomorrow-ish'] as $bad) {
            try {
                $this->place([$cbc], ['delivery_at' => $bad]);
                $this->fail("The delivery time [{$bad}] was accepted.");
            } catch (FrontDeskException) {
                // expected
            }
        }

        $this->assertSame(0, InvoiceLine::count());
    }

    public function test_the_printed_invoice_has_everything_the_clinics_paper_invoice_has(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:38', 'Asia/Dhaka'));
        app(Settings::class)->set('clinic.email', 'info@alpha.test');
        ['needle' => $needle, 'edta' => $edta] = $this->supplies();

        $haematology = ServiceCategory::create(['name' => 'Haematology', 'sort_order' => 2, 'room' => 'Sample collection (Room 106)']);
        $biochemistry = ServiceCategory::create(['name' => 'Biochemistry', 'sort_order' => 3, 'room' => 'Sample collection (Room 106)']);
        $imaging = ServiceCategory::create(['name' => 'Imaging', 'sort_order' => 1, 'room' => 'USG (Room 210)']);
        $cbc = $this->service('CBC with ESR', '400');
        $cbc->update(['category_id' => $haematology->id]);
        $fbs = $this->service('Fasting blood sugar', '150');
        $fbs->update(['category_id' => $biochemistry->id]);
        $usg = $this->service('USG of PP', '700', ServiceType::Imaging);
        $usg->update(['category_id' => $imaging->id]);
        $cbc->addons()->sync([$needle->id, $edta->id]);

        $doctor = ReferralDoctor::create(['code' => '003', 'name' => 'Dr. Yeasmin Jahan', 'degree' => 'MBBS, FCPS']);
        $this->openCash();
        $order = $this->place([$cbc, $fbs, $usg], [
            'referral_doctor_id' => $doctor->id,
            'notes' => 'C/O Akbor Sir',
            'discount_type' => 'amount', 'discount_value' => '150', 'discount_reason' => 'Regular',
        ]);
        $this->pay($order, '1000', PaymentMethod::Cash);
        $invoice = $this->invoice($order);

        $page = $this->signIn($this->ownerUser)->get(self::BASE."/print/invoice/{$invoice->public_id}")->assertOk();

        $page->assertSee($invoice->invoice_no)
            ->assertSee('Patient copy')
            ->assertSee('<svg', false)
            ->assertSee($invoice->patient->patient_code)
            ->assertSee('003, Dr. Yeasmin Jahan, MBBS, FCPS')
            ->assertSee('info@alpha.test')
            ->assertSee('C/O Akbor Sir')
            ->assertSee('16-Oct-2026 10:38 AM')
            ->assertSee('17-Oct-2026 07:00 PM')
            ->assertSeeInOrder(['CBC with ESR', 'Fasting blood sugar', 'USG of PP', $needle->name, $edta->name])
            ->assertSeeInOrder(['Total Tk.', '1,265.00', 'Discount Tk.', '150.00', 'Net payable', '1,115.00', 'Paid Tk.', '1,000.00', 'Due Tk.', '115.00'])
            ->assertSeeInOrder(['Initial payment (Cash)', '1,000.00'])
            ->assertSee('One Thousand Taka Only')
            ->assertDontSee('PART PAID')->assertDontSee('UNPAID')->assertDontSee('>PAID<', false)
            ->assertSeeInOrder(['1. USG (Room 210)', '2. Sample collection (Room 106)']);

        $this->assertSame(1, substr_count($page->getContent(), 'Sample collection (Room 106)'));

        $this->signIn($this->ownerUser)->get(self::BASE."/print/invoice/{$invoice->public_id}?copy=office")->assertSee('Office copy');
    }
}
