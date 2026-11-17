<?php

declare(strict_types=1);

namespace Modules\Messaging\Support;

use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Str;
use Modules\FrontDesk\Models\Order;
use Modules\Messaging\Models\ReportLink;
use Modules\Messaging\Permissions;

/**
 * Links that let a patient download their own report without signing in.
 *
 * The link's secret part is 40 random characters. Only its hash is stored,
 * so someone who reads the database cannot rebuild a link. A link stops
 * working after a set number of days, and can be withdrawn.
 */
final class ReportLinks
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ClinicContext $clinics,
    ) {}

    /** Make a new link for the order and return its full address. */
    public function create(Order $order): string
    {
        $token = Str::random(40);
        $days = max(1, min(90, (int) $this->settings->get(Permissions::SETTING_LINK_DAYS, 14)));

        ReportLink::create([
            'token_hash' => self::hash($token),
            'order_id' => $order->id,
            'expires_at' => now()->addDays($days),
        ]);

        return $this->baseUrl().'/r/'.$token;
    }

    public function find(string $token): ?ReportLink
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return ReportLink::where('token_hash', self::hash($token))->first();
    }

    /** Withdraw every link of an order, for example after a wrong number was used. */
    public function revoke(Order $order): int
    {
        return ReportLink::where('order_id', $order->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The clinic's own address, also when there is no web request (a queued job). */
    private function baseUrl(): string
    {
        $clinic = $this->clinics->require();
        $domain = $clinic->domains()->orderByDesc('is_primary')->orderBy('id')->value('domain');
        $scheme = str_starts_with((string) config('app.url'), 'http://') ? 'http' : 'https';

        return $scheme.'://'.$domain;
    }
}
