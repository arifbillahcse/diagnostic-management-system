<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use App\Core\Modules\ModuleManager;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Models\Study;
use Modules\Messaging\Support\Templates;

/** "Report ready" waits for imaging reports too, and the link opens one file with everything. */
class ImagingReadyTest extends MessagingTestCase
{
    private Service $usg;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('imaging');
        $this->usg = $this->service('USG whole abdomen', '1200', ServiceType::Imaging);
    }

    private function sign(Order $order, string $impression = '<p>Normal study.</p>'): Study
    {
        $this->actingAs($this->verifierUser);
        $study = Study::where('order_id', $order->id)->firstOrFail();

        return app(WriteReport::class)->sign($study, ['findings' => '<p>Liver normal.</p>', 'impression' => $impression], $study->fresh()->lock_version);
    }

    public function test_the_message_waits_for_the_imaging_report_then_the_link_opens_both(): void
    {
        $order = $this->labOrder([$this->cbc, $this->usg]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));

        $this->assertSame([], $this->sms(Templates::REPORT_READY), 'The ultrasound is not reported yet');

        $this->sign($order);

        $messages = $this->sms(Templates::REPORT_READY);
        $this->assertCount(1, $messages);

        $token = $this->linkToken($messages[0]);
        auth()->logout();
        $this->flushSession();

        $response = $this->get(self::BASE.'/r/'.$token);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_an_imaging_only_order_is_announced_once_and_not_again_after_an_amendment(): void
    {
        $order = $this->labOrder([$this->usg]);
        $study = $this->sign($order);
        $this->assertCount(1, $this->sms(Templates::REPORT_READY));

        $study = app(WriteReport::class)->amend($study, 'Typing error', $study->fresh()->lock_version);
        $this->sign($order, '<p>Mild fatty liver.</p>');

        $this->assertCount(1, $this->sms(Templates::REPORT_READY));
    }

    public function test_with_imaging_off_lab_results_alone_are_announced_as_before(): void
    {
        $this->disableModule('imaging');
        $order = $this->labOrder([$this->cbc, $this->usg]);
        $this->receiveSamples($order);
        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));

        $this->assertCount(1, $this->sms(Templates::REPORT_READY));
    }
}
