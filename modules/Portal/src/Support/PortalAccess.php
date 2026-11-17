<?php

declare(strict_types=1);

namespace Modules\Portal\Support;

use App\Core\Patients\PhoneNumber;
use App\Core\Tenancy\ClinicContext;
use Illuminate\Support\Facades\RateLimiter;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\Order;

/**
 * Who may see which order in the portal. A patient proves it is theirs with
 * the bill number and the mobile number on it; the order is then open in
 * their browser session for 30 minutes. Wrong tries are counted per bill
 * number, so a number cannot be guessed from many places at once.
 */
final class PortalAccess
{
    private const SESSION_KEY = 'portal.orders';

    private const MINUTES = 30;

    /** Wrong tries allowed per bill number in 15 minutes. */
    public const MAX_TRIES = 5;

    /** The order, or null. Never says which of the two was wrong. */
    public function find(string $billNo, string $mobile): ?Order
    {
        $billNo = strtoupper(trim($billNo));
        $phone = PhoneNumber::normalize($mobile);

        if ($billNo === '' || $phone === null || mb_strlen($billNo) > 40) {
            return null;
        }

        $invoice = Invoice::where('invoice_no', $billNo)->first();
        $order = $invoice ? Order::with('patient')->find($invoice->order_id) : null;

        if ($order === null || $order->status === OrderStatus::Cancelled || $order->patient?->phone_normalized !== $phone) {
            return null;
        }

        return $order;
    }

    public function tooManyTries(string $billNo): bool
    {
        return RateLimiter::tooManyAttempts($this->key($billNo), self::MAX_TRIES);
    }

    public function failed(string $billNo): void
    {
        RateLimiter::hit($this->key($billNo), 15 * 60);
    }

    public function grant(Order $order): void
    {
        $orders = array_filter((array) session(self::SESSION_KEY, []), fn ($until): bool => $until > now()->timestamp);
        $orders[$order->id] = now()->addMinutes(self::MINUTES)->timestamp;
        session()->put(self::SESSION_KEY, $orders);
        session()->regenerate();
    }

    public function allows(Order $order): bool
    {
        $until = ((array) session(self::SESSION_KEY, []))[$order->id] ?? 0;

        return $until > now()->timestamp;
    }

    public function forgetAll(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private function key(string $billNo): string
    {
        return 'portal:'.app(ClinicContext::class)->id().':'.strtoupper(trim($billNo));
    }
}
