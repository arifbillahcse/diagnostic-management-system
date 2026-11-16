<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use App\Core\Auth\RoleLimits;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Money\Money;
use Illuminate\Support\Facades\Gate;
use Modules\FrontDesk\Permissions;

/**
 * Turns a requested discount into an amount and decides whether the user
 * may give it.
 */
final class DiscountRules
{
    public function __construct(private readonly RoleLimits $limits) {}

    /** @param 'percent'|'amount'|null $type */
    public function amount(Money $subtotal, ?string $type, string|int|null $value): Money
    {
        if ($type === null || $value === null || $value === '') {
            return Money::zero();
        }

        try {
            $discount = match ($type) {
                'percent' => $subtotal->percent((string) $value),
                'amount' => Money::fromDecimal((string) $value),
                default => throw new FrontDeskException('The discount type is not valid.'),
            };
        } catch (\InvalidArgumentException) {
            throw new FrontDeskException('The discount value is not valid.');
        }

        if ($discount->isNegative() || $discount->greaterThan($subtotal)) {
            throw new FrontDeskException('The discount cannot be more than the order amount.');
        }

        return $discount;
    }

    /** Refuses with a clear message unless the user may give this discount. */
    public function authorize(User $user, Branch $branch, Money $subtotal, Money $discount, ?string $reason): void
    {
        if ($discount->isZero()) {
            return;
        }

        if (blank($reason)) {
            throw new FrontDeskException('A reason is required for a discount.');
        }

        if (Gate::forUser($user)->allows(Permissions::DISCOUNTS_OVERRIDE, $branch)) {
            return;
        }

        Gate::forUser($user)->authorize(Permissions::DISCOUNTS_APPLY, $branch);

        $limit = $this->limits->hundredthsFor($user, Permissions::LIMIT_DISCOUNT_PERCENT);

        // discount / subtotal <= limit%, compared in whole numbers:
        // discount * 100 * 100 <= limit(in hundredths) * subtotal.
        if ($limit !== RoleLimits::UNLIMITED && $discount->minor * 10_000 > $limit * $subtotal->minor) {
            $shown = rtrim(rtrim(number_format($limit / 100, 2, '.', ''), '0'), '.');

            throw new FrontDeskException("This discount is above your limit of {$shown}%. Ask someone who can approve larger discounts.");
        }
    }
}
