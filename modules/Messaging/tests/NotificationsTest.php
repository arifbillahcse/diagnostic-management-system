<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\ReportLink;
use Modules\Messaging\Models\Template;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\Messenger;
use Modules\Messaging\Support\Templates;
use RuntimeException;

final class NotificationsTest extends MessagingTestCase
{
    public function test_placing_an_order_sends_the_bill_by_sms_once(): void
    {
        $order = $this->labOrder([$this->cbc]);

        $message = $this->sms(Templates::ORDER_PLACED)[0];
        $this->assertSame('8801711000000', $message->recipient);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('test', $message->provider);
        $this->assertSame($order->id, $message->order_id);
        $this->assertStringContainsString($order->invoice->invoice_no, $message->body);
        $this->assertStringContainsString('Tk 400.00', $message->body);
        $this->assertStringContainsString('Alpha Diagnostics', $message->body);
        $this->assertStringNotContainsString('{', $message->body);

        // The same event again (a retried request) sends nothing more.
        event(new OrderPlaced($order));
        $this->assertCount(1, $this->sms());
    }

    public function test_nothing_is_sent_when_it_is_switched_off_or_there_is_no_usable_number(): void
    {
        $settings = app(Settings::class);

        $settings->set(Permissions::SETTING_NOTIFY_ORDER, false);
        $this->labOrder([$this->cbc]);

        $settings->set(Permissions::SETTING_NOTIFY_ORDER, true);
        $settings->set(Permissions::SETTING_SMS_ENABLED, false);
        $this->labOrder([$this->cbc]);

        $settings->set(Permissions::SETTING_SMS_ENABLED, true);
        $noPhone = $this->labPatient();
        $noPhone->forceFill(['phone' => '12345', 'phone_normalized' => '12345'])->save();
        $this->labOrder([$this->cbc], $noPhone);

        config(['dms.messaging.sms.driver' => 'off']);
        $this->labOrder([$this->cbc]);

        $this->assertCount(0, Message::all());

        config(['dms.messaging.sms.driver' => 'log']);
        $this->disableModule('messaging');
        $this->labOrder([$this->cbc]);
        $this->assertSame(0, DB::connection('clinic')->table('msg_messages')->count());
    }

    public function test_a_messaging_failure_never_stops_the_counter(): void
    {
        $this->app->bind(Messenger::class, fn () => throw new RuntimeException('gateway library exploded'));

        $order = $this->labOrder([$this->cbc]);

        $this->assertTrue($order->exists);
        $this->assertNotNull($order->invoice);
    }

    public function test_the_report_message_goes_out_once_when_the_last_result_is_released(): void
    {
        $order = $this->labOrder([$this->cbc, $this->glucose]);
        $this->receiveSamples($order);

        $this->enter($this->work($order, $this->cbc), ['HB' => '14.2', 'WBC' => '7500']);
        $this->verify($this->work($order, $this->cbc));
        $this->assertCount(0, $this->sms(Templates::REPORT_READY), 'one test is still waiting');
        $this->assertSame(0, ReportLink::count(), 'no link is made before a message carries it');

        $this->enter($this->work($order, $this->glucose), ['RBS' => '6.1']);
        $released = $this->verify($this->work($order, $this->glucose));

        $messages = $this->sms(Templates::REPORT_READY);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('http://alpha.test/r/', str_replace('https://', 'http://', $messages[0]->body));
        $this->assertSame(1, ReportLink::count());

        event(new ResultsReleased($released));
        $this->assertCount(1, $this->sms(Templates::REPORT_READY));
        $this->assertSame(1, ReportLink::count());
    }

    public function test_the_link_can_be_left_out_and_the_message_switched_off(): void
    {
        app(Settings::class)->set(Permissions::SETTING_REPORT_LINK, false);
        $this->releasedOrder();

        $message = $this->sms(Templates::REPORT_READY)[0];
        $this->assertStringNotContainsString('/r/', $message->body);
        $this->assertStringEndsWith('Alpha Diagnostics.', $message->body);
        $this->assertSame(0, ReportLink::count());

        app(Settings::class)->set(Permissions::SETTING_NOTIFY_REPORT, false);
        $this->releasedOrder();
        $this->assertCount(1, $this->sms(Templates::REPORT_READY));
    }

    public function test_the_clinic_can_change_the_wording_or_switch_one_message_off(): void
    {
        app(Templates::class)->ensureDefaults();
        $this->assertSame(6, Template::count());

        Template::where('template_key', Templates::ORDER_PLACED)->where('channel', 'sms')
            ->update(['body' => 'Bill {bill_no}: Tk {total}. Thank you, {patient}. {unknown_word}']);
        $order = $this->labOrder([$this->cbc]);

        $this->assertSame("Bill {$order->invoice->invoice_no}: Tk 400.00. Thank you, {$order->patient->name}.", $this->sms()[0]->body);

        Template::where('template_key', Templates::ORDER_PLACED)->where('channel', 'sms')->update(['is_active' => false]);
        $this->labOrder([$this->cbc]);
        $this->assertCount(1, $this->sms());
    }

    public function test_email_goes_to_patients_who_have_an_address(): void
    {
        config(['mail.default' => 'array']);
        app(Settings::class)->set(Permissions::SETTING_EMAIL_ENABLED, true);
        app(Settings::class)->set(Permissions::SETTING_SMS_ENABLED, false);

        $patient = $this->labPatient();
        $patient->forceFill(['email' => 'Asma@Example.com'])->save();
        $order = $this->labOrder([$this->cbc], $patient);

        $message = Message::sole();
        $this->assertSame(Channel::Email, $message->channel);
        $this->assertSame('asma@example.com', $message->recipient);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame("Your bill {$order->invoice->invoice_no} at Alpha Diagnostics", $message->subject);

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Tk 400.00', $sent[0]->getOriginalMessage()->getTextBody());
    }

    public function test_orders_stay_in_their_own_clinic(): void
    {
        $this->labOrder([$this->cbc]);
        $this->assertSame(1, Message::count());

        $this->useClinic('beta');
        $this->assertFalse(app(ModuleState::class)->isEnabled('messaging'));
        $this->assertSame(0, Order::count());
    }
}
