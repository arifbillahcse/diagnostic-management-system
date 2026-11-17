<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Settings\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\Messaging\Actions\ManageMessages;
use Modules\Messaging\Console\DueRemindersCommand;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Filament\ListMessages;
use Modules\Messaging\Filament\ManageTemplates;
use Modules\Messaging\Filament\MessagingSettings;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\OptOut;
use Modules\Messaging\Models\Template;
use Modules\Messaging\Permissions;
use Modules\Messaging\Support\MessagingException;
use Modules\Messaging\Support\Templates;

final class StaffAndRemindersTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function failedMessage(): Message
    {
        config(['dms.messaging.sms' => ['driver' => 'http', 'url' => 'https://sms.example/send', 'params' => 'to={to}&text={message}']]);
        Http::fake(['sms.example/*' => Http::sequence()->push('down', 500)->whenEmpty(Http::response('OK', 200))]);
        $this->labOrder([$this->cbc]);

        return Message::sole();
    }

    public function test_a_failed_message_is_sent_again_by_staff_who_may(): void
    {
        $message = $this->failedMessage();
        $this->assertSame(MessageStatus::Failed, $message->status);

        $this->actingAs($this->staff('receptionist'));
        try {
            app(ManageMessages::class)->resend($message);
            $this->fail('A receptionist sent a message again.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->actingAs($this->ownerUser);
        $this->assertSame(MessageStatus::Sent, app(ManageMessages::class)->resend($message)->status);

        $this->expectException(MessagingException::class);
        app(ManageMessages::class)->resend($message->fresh());
    }

    public function test_messages_to_a_number_can_be_stopped_and_allowed_again(): void
    {
        $actions = app(ManageMessages::class);

        $actions->stop('8801711000000');
        $actions->stop('8801711000000');
        $this->assertSame(1, OptOut::count());
        $this->assertSame(1, AuditLog::where('action', 'messaging.stopped')->count());

        $this->labOrder([$this->cbc]);
        $this->assertCount(0, Message::all());

        $actions->allow('8801711000000');
        $this->labOrder([$this->cbc]);
        $this->assertCount(1, Message::all());

        $this->actingAs($this->staff('receptionist'));
        $this->expectException(AuthorizationException::class);
        $actions->stop('8801711000000');
    }

    public function test_due_reminders_follow_the_clinics_rhythm_and_stop_after_three(): void
    {
        $start = CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka');
        $this->travelTo($start);
        app(Settings::class)->set(Permissions::SETTING_NOTIFY_ORDER, false);

        $unpaid = $this->labOrder([$this->cbc]);
        $paid = $this->labOrder([$this->glucose]);
        $this->pay($paid, '150', PaymentMethod::Bkash);

        $this->assertSame(0, DueRemindersCommand::remind(), 'reminders are off until the clinic sets the days');
        app(Settings::class)->set(Permissions::SETTING_DUE_REMINDER_DAYS, 3);

        $sentOn = [];

        for ($day = 0; $day <= 20; $day++) {
            $this->travelTo($start->addDays($day)->setTime(10, 30));

            if (DueRemindersCommand::remind() > 0) {
                $sentOn[] = $day;
            }

            DueRemindersCommand::remind(); // running it twice a day changes nothing
        }

        $this->assertSame([3, 6, 9], $sentOn);

        $reminders = $this->sms(Templates::DUE_REMINDER);
        $this->assertCount(3, $reminders);
        $this->assertSame([$unpaid->id], array_values(array_unique(array_map(fn (Message $m) => $m->order_id, $reminders))));
        $this->assertStringContainsString('Tk 400.00 is due', $reminders[0]->body);
    }

    public function test_a_bill_paid_between_reminders_gets_no_more(): void
    {
        $start = CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka');
        $this->travelTo($start);
        app(Settings::class)->set(Permissions::SETTING_NOTIFY_ORDER, false);
        app(Settings::class)->set(Permissions::SETTING_DUE_REMINDER_DAYS, 2);
        $order = $this->labOrder([$this->cbc]);

        $this->travelTo($start->addDays(2));
        $this->assertSame(1, DueRemindersCommand::remind());

        $this->pay($order, '400', PaymentMethod::Bkash);
        $this->travelTo($start->addDays(4));
        $this->assertSame(0, DueRemindersCommand::remind());

        $this->artisan('messaging:due-reminders')->expectsOutputToContain('0 reminder(s) queued')->assertSuccessful();
    }

    public function test_the_screens_follow_the_permissions(): void
    {
        $this->labOrder([$this->cbc]);

        foreach (['/messaging/log', '/messaging/wording', '/messaging/settings'] as $path) {
            $this->signIn($this->ownerUser)->get(self::BASE.$path)->assertOk();
        }

        $receptionist = $this->staff('receptionist');
        $this->signIn($receptionist)->get(self::BASE.'/messaging/log')->assertOk()->assertSee('8801711000000');
        $this->signIn($receptionist)->get(self::BASE.'/messaging/settings')->assertForbidden();
        $this->signIn($receptionist)->get(self::BASE.'/messaging/wording')->assertForbidden();
        $this->signIn($this->technician)->get(self::BASE.'/messaging/log')->assertForbidden();

        $this->actingAs($this->ownerUser);
        $this->disableModule('messaging');
        $this->signIn($this->ownerUser)->get(self::BASE.'/messaging/log')->assertForbidden();
    }

    public function test_settings_are_saved_and_a_test_sms_is_sent_from_the_screen(): void
    {
        Livewire::test(MessagingSettings::class)
            ->assertFormSet(['sms_enabled' => true, 'email_enabled' => false, 'link_days' => 14, 'due_reminder_days' => 0])
            ->assertSee('test mode')
            ->fillForm(['email_enabled' => true, 'link_days' => 7, 'due_reminder_days' => 5, 'notify_order' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Messaging settings saved');

        $settings = app(Settings::class);
        $this->assertTrue($settings->get(Permissions::SETTING_EMAIL_ENABLED));
        $this->assertSame(7, $settings->get(Permissions::SETTING_LINK_DAYS));
        $this->assertSame(5, $settings->get(Permissions::SETTING_DUE_REMINDER_DAYS));
        $this->assertFalse($settings->get(Permissions::SETTING_NOTIFY_ORDER));
        $this->assertSame(7, AuditLog::where('action', 'settings.updated')->latest('id')->first()->after[Permissions::SETTING_LINK_DAYS]);

        Livewire::test(MessagingSettings::class)->callAction('test', ['phone' => '123'])->assertNotified('Not sent');
        Livewire::test(MessagingSettings::class)->callAction('test', ['phone' => '01812-345678'])->assertNotified('Test message queued');

        $test = Message::where('template_key', Templates::TEST)->sole();
        $this->assertSame('8801812345678', $test->recipient);
        $this->assertSame(MessageStatus::Sent, $test->status);
    }

    public function test_wording_is_edited_and_the_log_actions_work_from_the_screens(): void
    {
        Livewire::test(ManageTemplates::class)->assertSee('Bill confirmation')->assertSee('Due reminder');
        $template = Template::where('template_key', Templates::ORDER_PLACED)->where('channel', 'sms')->sole();

        Livewire::test(ManageTemplates::class)
            ->callTableAction('edit', $template, ['body' => 'Bill {bill_no}, Tk {total}.', 'is_active' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Bill {bill_no}, Tk {total}.', $template->fresh()->body);

        $message = $this->failedMessage();
        $this->assertStringEndsWith('Tk 400.00.', $message->body);

        Livewire::test(ListMessages::class)
            ->assertTableActionVisible('resend', $message)
            ->callTableAction('resend', $message)
            ->assertNotified('Message queued again');
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);

        Livewire::test(ListMessages::class)
            ->assertTableActionHidden('resend', $message)
            ->assertTableActionHidden('allow', $message)
            ->callTableAction('stop', $message)
            ->assertNotified('Messages stopped');
        $this->assertTrue(OptOut::where('recipient', '8801711000000')->exists());

        Livewire::test(ListMessages::class)->assertTableActionVisible('allow', $message)->assertTableActionHidden('stop', $message);

        $this->actingAs($this->staff('receptionist'));
        Livewire::test(ListMessages::class)->assertTableActionHidden('allow', $message)->assertTableActionHidden('resend', $message);
    }
}
