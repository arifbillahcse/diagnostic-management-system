<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Console\RetryCommand;
use Modules\Messaging\Drivers\HttpSmsDriver;
use Modules\Messaging\Enums\MessageStatus;
use Modules\Messaging\Jobs\SendMessage;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Support\SmsText;

final class GatewayTest extends MessagingTestCase
{
    private const KEY = 'SECRET-KEY-123';

    private function useHttpGateway(array $extra = []): void
    {
        config(['dms.messaging.sms' => $extra + [
            'driver' => 'http',
            'url' => 'https://sms.example/api/send',
            'method' => 'post',
            'params' => 'api_key={api_key}&senderid={sender}&number={to}&message={message}&type=text',
            'api_key' => self::KEY,
            'sender' => 'ALPHA',
            'success' => null,
        ]]);
    }

    public function test_a_message_is_posted_to_the_gateway_with_the_configured_fields(): void
    {
        $this->useHttpGateway();
        Http::fake(['sms.example/*' => Http::response('OK 778899', 200)]);

        $order = $this->labOrder([$this->cbc]);

        $message = Message::sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('http', $message->provider);
        $this->assertSame('OK 778899', $message->provider_ref);
        $this->assertSame(1, $message->attempts);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sms.example/api/send'
            && $request->method() === 'POST'
            && $request['api_key'] === self::KEY
            && $request['senderid'] === 'ALPHA'
            && $request['number'] === '8801711000000'
            && $request['type'] === 'text'
            && str_contains($request['message'], $order->invoice->invoice_no));
    }

    public function test_a_get_gateway_gets_the_fields_in_the_address(): void
    {
        $this->useHttpGateway(['method' => 'get']);
        Http::fake(['sms.example/*' => Http::response('queued', 200)]);

        $this->labOrder([$this->cbc]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), 'number=8801711000000'));
        $this->assertSame(MessageStatus::Sent, Message::sole()->status);
    }

    public function test_a_refusal_is_recorded_without_the_secret_key(): void
    {
        $this->useHttpGateway();
        Http::fake(['sms.example/*' => Http::response('Invalid api_key '.self::KEY, 401)]);

        $this->labOrder([$this->cbc]);

        $message = Message::sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('answered 401', $message->error);
        $this->assertStringNotContainsString(self::KEY, $message->error);
        $this->assertNull($message->sent_at);
    }

    public function test_a_reply_without_the_expected_text_counts_as_a_failure(): void
    {
        $this->useHttpGateway(['success' => 'SUCCESS']);
        Http::fake(['sms.example/*' => Http::sequence()->push('ERROR low balance', 200)->push('SUCCESS id=5', 200)]);

        $this->labOrder([$this->cbc]);
        $this->assertSame(MessageStatus::Failed, Message::sole()->status);
        $this->assertStringContainsString('low balance', Message::sole()->error);

        $this->assertSame(1, RetryCommand::retry());
        $message = Message::sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(2, $message->attempts);
        $this->assertNull($message->error);
    }

    public function test_an_unreachable_gateway_fails_the_message_and_retries_stop_after_three_tries(): void
    {
        $this->useHttpGateway();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for '.self::KEY));

        $this->labOrder([$this->cbc]);
        $this->assertStringContainsString('Could not reach the SMS gateway', Message::sole()->error);
        $this->assertStringNotContainsString(self::KEY, Message::sole()->error);

        $this->assertSame(1, RetryCommand::retry());
        $this->assertSame(1, RetryCommand::retry());
        $this->assertSame(0, RetryCommand::retry(), 'three tries in all');
        $this->assertSame(3, Message::sole()->attempts);
        $this->assertSame(MessageStatus::Failed, Message::sole()->status);

        // An old failure is left for staff.
        Message::query()->update(['attempts' => 1]);
        $this->travel(3)->hours();
        $this->assertSame(0, RetryCommand::retry());
    }

    public function test_an_address_that_is_not_https_is_never_called(): void
    {
        Http::fake();

        foreach (['http://sms.example/send', '', 'ftp://sms.example'] as $url) {
            $result = (new HttpSmsDriver(['url' => $url, 'params' => 'to={to}']))->send('8801711000000', 'Hello');
            $this->assertFalse($result->ok);
        }

        Http::assertNothingSent();
    }

    public function test_a_message_is_not_sent_twice(): void
    {
        $this->useHttpGateway();
        Http::fake(['sms.example/*' => Http::response('OK', 200)]);

        $this->labOrder([$this->cbc]);
        $message = Message::sole();

        SendMessage::dispatchSync($message->id);
        SendMessage::dispatchSync($message->id);

        Http::assertSentCount(1);
        $this->assertSame(1, $message->fresh()->attempts);
    }

    public function test_sms_parts_are_counted_the_way_providers_charge(): void
    {
        $this->assertSame(1, SmsText::segments(str_repeat('a', 160)));
        $this->assertSame(2, SmsText::segments(str_repeat('a', 161)));
        $this->assertSame(2, SmsText::segments(str_repeat('a', 306)));
        $this->assertSame(3, SmsText::segments(str_repeat('a', 307)));
        $this->assertSame(1, SmsText::segments(str_repeat('a', 158).'{'), 'a bracket takes two places');
        $this->assertSame(2, SmsText::segments(str_repeat('a', 159).'{'));
        $this->assertSame(1, SmsText::segments(str_repeat('ক', 70)));
        $this->assertSame(2, SmsText::segments(str_repeat('ক', 71)));
        $this->assertSame(1, SmsText::segments(''));

        $this->labOrder([$this->cbc]);
        $this->assertSame(1, Message::sole()->segments);
    }
}
