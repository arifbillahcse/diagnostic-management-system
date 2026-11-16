<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\CommissionEntry;
use Modules\Finance\Models\CommissionRule;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Models\Invoice;
use Modules\FrontDesk\Models\OrderItem;
use Modules\FrontDesk\Models\ReferralDoctor;

/**
 * Works out referral commission.
 *
 * A doctor earns commission on a test when its invoice is fully paid, on
 * what the patient actually paid for that test (its price less its share
 * of any discount). If the test is cancelled, or the invoice stops being
 * fully paid, the commission is taken back with an opposite entry.
 */
final class Commission
{
    /**
     * Bring the commission entries of one invoice in line with its current
     * state. Safe to run any number of times.
     */
    public function settle(Invoice $invoice): void
    {
        DB::connection('clinic')->transaction(function () use ($invoice): void {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $order = $invoice->order;
            $doctorId = $order->referral_doctor_id;
            $earns = $doctorId !== null && $invoice->status === InvoiceStatus::Paid;

            $items = $order->items()->whereNull('package_item_of')->get();
            $rules = $doctorId === null ? collect() : CommissionRule::where('referral_doctor_id', $doctorId)->get();

            $existing = CommissionEntry::where('invoice_id', $invoice->id)->get()->groupBy('order_item_id');

            foreach ($items as $item) {
                $entries = $existing->get($item->id, collect());
                $held = Money::sum(...$entries->map->amount->all());
                $expected = $earns && $item->isActive() ? $this->rate($rules, $item) : Money::zero();

                // Already earned and still earned: leave it at the rate it
                // was earned at, even if the doctor's rate changed since.
                if ($held->isPositive() && $expected->isPositive()) {
                    continue;
                }

                $delta = $expected->minus($held);

                if ($delta->isZero()) {
                    continue;
                }

                CommissionEntry::create([
                    'referral_doctor_id' => $entries->first()?->referral_doctor_id ?? $doctorId,
                    'order_item_id' => $item->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $delta,
                    'reason' => match (true) {
                        $delta->isPositive() => 'Invoice paid',
                        ! $item->isActive() => 'Test cancelled',
                        default => 'Invoice no longer fully paid',
                    },
                    'created_at' => now(),
                ]);
            }
        });
    }

    /** What the doctor is owed: everything earned, less everything paid out. */
    public function balance(ReferralDoctor $doctor): Money
    {
        return $this->earned($doctor)->minus($this->paid($doctor));
    }

    public function earned(ReferralDoctor $doctor): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fin_commission_entries')->where('referral_doctor_id', $doctor->id)->sum('amount'));
    }

    public function paid(ReferralDoctor $doctor): Money
    {
        return MoneyCast::fromDatabase(DB::connection('clinic')->table('fin_commission_payouts')->where('referral_doctor_id', $doctor->id)->sum('amount'));
    }

    /** @param Collection<int, CommissionRule> $rules */
    private function rate($rules, OrderItem $item): Money
    {
        $rule = $rules->firstWhere('service_id', $item->service_id) ?? $rules->firstWhere('service_id', null);

        if ($rule === null || $item->net_amount->isZero()) {
            return Money::zero();
        }

        if ($rule->kind === CommissionRule::PERCENT) {
            return $item->net_amount->percent($rule->valueText());
        }

        // A fixed amount never exceeds what the patient paid for the test.
        $fixed = Money::fromDecimal($rule->valueText());

        return $fixed->greaterThan($item->net_amount) ? $item->net_amount : $fixed;
    }
}
