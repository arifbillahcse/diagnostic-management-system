<?php

declare(strict_types=1);

namespace Modules\Owner\Tests;

use App\Core\Modules\ModuleManager;
use DomainException;
use Modules\FrontDesk\Actions\AdjustOrder;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Owner\Support\SalesReports;

final class SalesReportsTest extends FrontDeskTestCase
{
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('owner');
        $this->today = now('Asia/Dhaka')->toDateString();
    }

    private function reports(): SalesReports
    {
        return app(SalesReports::class);
    }

    public function test_sales_by_test_leave_out_cancelled_tests_and_package_contents(): void
    {
        $cbc = $this->service('CBC', '400');
        $esr = $this->service('ESR', '200');
        $package = $this->package('Fever panel', '500', $cbc, $esr);

        $this->order([$cbc, $esr], ['discount_type' => 'amount', 'discount_value' => '60', 'discount_reason' => 'Offer']);
        $this->order([$cbc]);
        $this->order([$package]);
        $cancelled = $this->order([$esr, $cbc]);
        app(AdjustOrder::class)->cancelItem($cancelled->items[0], 'Not done');
        app(AdjustOrder::class)->cancelOrder($this->order([$cbc]), 'Wrong patient');

        $rows = collect($this->reports()->byTest($this->today, $this->today))->keyBy('name');

        $this->assertSame(['CBC', 'Fever panel', 'ESR'], $rows->keys()->all(), 'highest net first');
        $this->assertSame([3, '1200.00', '40.00', '1160.00'], [$rows['CBC']['count'], $rows['CBC']['gross']->toDecimal(), $rows['CBC']['discount']->toDecimal(), $rows['CBC']['net']->toDecimal()]);
        $this->assertSame([1, '180.00'], [$rows['ESR']['count'], $rows['ESR']['net']->toDecimal()]);
        $this->assertSame([1, '500.00'], [$rows['Fever panel']['count'], $rows['Fever panel']['net']->toDecimal()]);
    }

    public function test_sales_by_doctor_group_walk_ins_as_self(): void
    {
        $karim = ReferralDoctor::create(['name' => 'Dr. Karim']);
        $this->order(['1000'], ['referral_doctor_id' => $karim->id]);
        $this->order(['500', '300'], ['referral_doctor_id' => $karim->id]);
        $this->order(['200']);

        $rows = $this->reports()->byDoctor($this->today, $this->today);

        $this->assertSame([['Dr. Karim', 2, '1800.00'], ['Self (no referral)', 1, '200.00']], array_map(fn ($r) => [$r['name'], $r['orders'], $r['net']->toDecimal()], $rows));
    }

    public function test_collections_by_cashier_and_method(): void
    {
        $this->openCash();
        $order = $this->order(['1000']);
        $this->pay($order, '300', PaymentMethod::Cash);
        $this->pay($order, '200', PaymentMethod::Cash);
        $this->pay($order, '100', PaymentMethod::Bkash);

        $receptionist = $this->staff('receptionist');
        $receptionist->update(['name' => 'Rina']);
        $this->actingAs($receptionist);
        $this->pay($order, '50', PaymentMethod::Bkash);

        $rows = array_map(fn ($r) => [$r['name'], $r['method'], $r['payments'], $r['amount']->toDecimal()], $this->reports()->byCashier($this->today, $this->today));

        $this->assertEqualsCanonicalizing([
            [$this->ownerUser->name, 'bkash', 1, '100.00'],
            [$this->ownerUser->name, 'cash', 2, '500.00'],
            ['Rina', 'bkash', 1, '50.00'],
        ], $rows);
    }

    public function test_the_daily_report_has_a_row_for_every_day_in_the_period(): void
    {
        $order = $this->order(['1000']);
        $this->pay($order, '400');
        $from = now('Asia/Dhaka')->subDays(2)->toDateString();

        $rows = $this->reports()->byDay($from, $this->today);

        $this->assertCount(3, $rows);
        $this->assertSame([0, '0.00'], [$rows[0]['orders'], $rows[0]['collected']->toDecimal()]);
        $this->assertSame([1, 1, '1000.00', '400.00'], [$rows[2]['orders'], $rows[2]['patients'], $rows[2]['billed']->toDecimal(), $rows[2]['collected']->toDecimal()]);
    }

    public function test_the_period_is_checked(): void
    {
        foreach ([
            [$this->today, now('Asia/Dhaka')->subDay()->toDateString()],
            [now('Asia/Dhaka')->subDays(92)->toDateString(), $this->today],
        ] as [$from, $to]) {
            try {
                $this->reports()->byDay($from, $to);
                $this->fail('The period should be refused.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(92, $this->reports()->byDay(now('Asia/Dhaka')->subDays(91)->toDateString(), $this->today));
    }
}
