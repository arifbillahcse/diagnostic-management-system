<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use Modules\FrontDesk\Models\Order;
use Modules\Laboratory\Tests\LabTestCase;
use Modules\Messaging\Enums\Channel;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Permissions;

/**
 * Shared set-up: the module switched on, SMS switched on, and the
 * installation's gateway in test mode (nothing leaves the machine).
 */
abstract class MessagingTestCase extends LabTestCase
{
    protected const BASE = 'http://alpha.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['dms.messaging.sms' => ['driver' => 'log']]);
        app(ModuleManager::class)->enable('messaging');
        app(Settings::class)->set(Permissions::SETTING_SMS_ENABLED, true);
        $this->actingAs($this->ownerUser);
    }

    /** @return list<Message> */
    protected function sms(?string $template = null): array
    {
        return Message::where('channel', Channel::Sms->value)
            ->when($template, fn ($q) => $q->where('template_key', $template))
            ->orderBy('id')->get()->all();
    }

    /** An order with one blood count, taken to a released result. */
    protected function releasedOrder(): Order
    {
        return $this->releasedCbc()->order;
    }

    protected function linkToken(Message $message): string
    {
        $this->assertMatchesRegularExpression('#/r/[A-Za-z0-9]{40}#', $message->body);
        preg_match('#/r/([A-Za-z0-9]{40})#', $message->body, $match);

        return $match[1];
    }
}
