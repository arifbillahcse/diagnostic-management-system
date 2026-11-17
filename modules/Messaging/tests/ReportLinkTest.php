<?php

declare(strict_types=1);

namespace Modules\Messaging\Tests;

use App\Core\Audit\AuditLog;
use App\Core\Modules\ModuleManager;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use App\Core\Tenancy\ClinicManager;
use Modules\Messaging\Models\ReportLink;
use Modules\Messaging\Support\ReportLinks;
use Modules\Messaging\Support\Templates;
use Modules\Reports\Permissions as ReportPermissions;

final class ReportLinkTest extends MessagingTestCase
{
    private function tokenFor($order): string
    {
        return $this->linkToken($this->sms(Templates::REPORT_READY)[0]);
    }

    private function open(string $token)
    {
        auth()->logout();
        $this->flushSession();

        return $this->get(self::BASE.'/r/'.$token);
    }

    public function test_the_patient_opens_the_report_without_signing_in_and_it_is_recorded(): void
    {
        $order = $this->releasedOrder();
        $token = $this->tokenFor($order);

        $response = $this->open($token)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        $link = ReportLink::sole();
        $this->assertSame(1, $link->opened_count);
        $this->assertNotNull($link->last_opened_at);
        $this->assertSame($order->order_no, AuditLog::where('action', 'report.link_opened')->sole()->context['order_no']);
    }

    public function test_only_a_hash_of_the_link_is_stored(): void
    {
        $token = $this->tokenFor($this->releasedOrder());
        $link = ReportLink::sole();

        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertStringNotContainsString($token, json_encode($link->getAttributes()));
    }

    public function test_a_wrong_expired_or_withdrawn_link_shows_nothing(): void
    {
        $order = $this->releasedOrder();
        $token = $this->tokenFor($order);

        $this->open(str_repeat('a', 40))->assertNotFound()->assertSee('does not work any more');
        $this->open('short')->assertNotFound();
        $this->open(substr($token, 0, 39).($token[39] === 'x' ? 'y' : 'x'))->assertNotFound();

        $this->travel(15)->days();
        $this->open($token)->assertNotFound()->assertDontSee($order->order_no);
        $this->travelBack();

        $this->open($token)->assertOk();
        $this->assertSame(1, app(ReportLinks::class)->revoke($order));
        $this->open($token)->assertNotFound();

        $this->assertSame(1, ReportLink::sole()->opened_count);
    }

    public function test_the_link_follows_the_rule_on_unpaid_bills(): void
    {
        $order = $this->releasedOrder(); // nothing was paid
        $token = $this->tokenFor($order);

        app(Settings::class)->set(ReportPermissions::SETTING_BLOCK_WHEN_DUE, true);

        $this->open($token)->assertForbidden()->assertSee('contact the clinic');
        $this->assertSame(0, ReportLink::sole()->opened_count);
    }

    public function test_a_link_does_not_work_at_another_clinics_address_or_with_the_module_off(): void
    {
        $token = $this->tokenFor($this->releasedOrder());
        $alpha = app(ClinicContext::class)->require();

        // Beta has the module on too, but the link belongs to Alpha's database.
        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->run($beta, fn () => app(ModuleManager::class)->enable('messaging'));
        auth()->logout();
        $this->get('http://beta.test/r/'.$token)->assertNotFound();

        app(ClinicManager::class)->activate($alpha);
        $this->actingAs($this->ownerUser);
        $this->disableModule('messaging');
        $this->open($token)->assertNotFound();
    }

    public function test_guessing_is_slowed_down(): void
    {
        auth()->logout();

        for ($i = 0; $i < 20; $i++) {
            $this->get(self::BASE.'/r/'.str_repeat('b', 40))->assertNotFound();
        }

        $this->get(self::BASE.'/r/'.str_repeat('b', 40))->assertStatus(429);
    }
}
