<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends through any SMS gateway that takes a simple web request, which is
 * how most bulk SMS providers work. It is set up once for the whole
 * installation, in the environment file:
 *
 *   SMS_HTTP_URL=https://provider.example/api/send
 *   SMS_HTTP_METHOD=post
 *   SMS_HTTP_PARAMS="api_key={api_key}&senderid={sender}&number={to}&message={message}"
 *   SMS_API_KEY=...
 *   SMS_SENDER_ID=...
 *   SMS_HTTP_SUCCESS_TEXT=      (optional: text the reply must contain)
 *
 * Clinics cannot change the address, so a clinic cannot make the server
 * call a place of its choosing.
 */
final class HttpSmsDriver implements SmsDriver
{
    /** @param array{url: ?string, method: ?string, params: ?string, api_key: ?string, sender: ?string, success: ?string} $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'http';
    }

    public function send(string $to, string $text): SendResult
    {
        $url = (string) ($this->config['url'] ?? '');

        if (! str_starts_with($url, 'https://')) {
            return SendResult::failed('The SMS gateway address is not set, or does not start with https://.');
        }

        parse_str((string) ($this->config['params'] ?? ''), $params);

        $values = [
            '{api_key}' => (string) ($this->config['api_key'] ?? ''),
            '{sender}' => (string) ($this->config['sender'] ?? ''),
            '{to}' => $to,
            '{message}' => $text,
        ];
        $params = array_map(fn ($value): string => strtr((string) $value, $values), $params);

        try {
            $request = Http::timeout(15)->connectTimeout(5);
            $response = strtolower((string) ($this->config['method'] ?? 'post')) === 'get'
                ? $request->get($url, $params)
                : $request->asForm()->post($url, $params);
        } catch (Throwable $e) {
            return SendResult::failed($this->withoutSecrets('Could not reach the SMS gateway: '.$e->getMessage()));
        }

        $body = trim($response->body());
        $expected = (string) ($this->config['success'] ?? '');

        if (! $response->successful()) {
            return SendResult::failed($this->withoutSecrets("The SMS gateway answered {$response->status()}: ".mb_substr($body, 0, 300)));
        }

        if ($expected !== '' && ! str_contains($body, $expected)) {
            return SendResult::failed($this->withoutSecrets('The SMS gateway refused the message: '.mb_substr($body, 0, 300)));
        }

        return SendResult::sent(mb_substr($body, 0, 190) ?: null);
    }

    /** The key must never end up in the message log. */
    private function withoutSecrets(string $text): string
    {
        $key = (string) ($this->config['api_key'] ?? '');

        if ($key !== '') {
            $text = str_replace([$key, rawurlencode($key), urlencode($key)], '***', $text);
        }

        return mb_substr($text, 0, 480);
    }
}
