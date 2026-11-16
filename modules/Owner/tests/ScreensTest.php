<?php

declare(strict_types=1);

namespace Modules\Owner\Tests;

use App\Core\Modules\ModuleManager;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Owner\Filament\CollectionsChart;
use Modules\Owner\Filament\MonthWidget;
use Modules\Owner\Filament\SalesReportsPage;

final class ScreensTest extends FrontDeskTestCase
{
    private const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('owner');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_each_sales_report_shows_its_figures_and_totals(): void
    {
        $doctor = ReferralDoctor::create(['name' => 'Dr. Karim']);
        $cbc = $this->service('Complete blood count', '400');
        $order = $this->order([$cbc, $this->service('Lipid profile', '1200')], ['referral_doctor_id' => $doctor->id]);
        $this->order([$cbc]);
        $this->pay($order, '1000');

        Livewire::test(SalesReportsPage::class)
            ->assertSee('Day by day')
            ->assertSeeInOrder(['Total', '2', '2', '2,000.00', '1,000.00'])
            ->set('report', 'test')
            ->assertSeeInOrder(['Lipid profile', '1', '1,200.00', 'Complete blood count', '2', '800.00', 'Total', '3', '2,000.00'])
            ->set('report', 'doctor')
            ->assertSeeInOrder(['Dr. Karim', '1', '1,600.00', 'Self (no referral)', '1', '400.00'])
            ->set('report', 'cashier')
            ->assertSeeInOrder([$this->ownerUser->name, 'bKash', '1', '1,000.00'])
            ->set('from', now('Asia/Dhaka')->subDays(200)->toDateString())
            ->assertSee('Choose a period of 92 days or less.')
            ->set('report', 'nonsense')
            ->assertSee('Choose a report.');
    }

    public function test_the_dashboard_widgets_show_the_month_and_work_without_finance(): void
    {
        $order = $this->order(['1500']);
        $this->pay($order, '1000');

        Livewire::test(MonthWidget::class)
            ->assertSee('This month so far')
            ->assertSee('1,500.00')
            ->assertSee('1,000.00')
            ->assertDontSee('Left after expenses');

        Livewire::test(CollectionsChart::class)->assertSee('Collections, last 14 days');

        app(ModuleManager::class)->enable('finance');
        Livewire::test(MonthWidget::class)->assertSee('Left after expenses');
    }

    public function test_access_follows_the_roles_and_the_module_switch(): void
    {
        $url = self::BASE.'/sales-reports';

        $this->signIn($this->ownerUser)->get($url)->assertOk();
        $this->signIn($this->staff('accountant'))->get($url)->assertOk();
        $this->signIn($this->staff('receptionist'))->get($url)->assertForbidden();

        $this->actingAs($this->staff('accountant'));
        $this->assertFalse(MonthWidget::canView(), 'the accountant gets the reports, not the owner\'s dashboard');
        $this->actingAs($this->ownerUser);
        $this->assertTrue(MonthWidget::canView());

        $this->disableModule('owner');
        $this->signIn($this->ownerUser)->get($url)->assertForbidden();
        $this->assertFalse(MonthWidget::canView());
    }
}
