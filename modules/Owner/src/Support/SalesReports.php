<?php

declare(strict_types=1);

namespace Modules\Owner\Support;

use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\ItemStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Support\DailyFigures;

/**
 * Sales figures for the owner, over a period of the clinic's local days.
 * Every report covers all branches.
 */
final class SalesReports
{
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly DailyFigures $daily,
        private readonly ClinicContext $clinics,
    ) {}

    /**
     * One row per day: orders, patients, billed, collected, refunded.
     *
     * @return list<array{date: CarbonImmutable, orders: int, patients: int, billed: Money, collected: Money, refunded: Money}>
     */
    public function byDay(string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);
        $branches = Branch::pluck('id')->map(fn ($id): int => (int) $id)->all();
        $period = $this->daily->forPeriod($start, $end, $branches);
        $rows = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $row = ['date' => $day, 'orders' => 0, 'patients' => 0, 'billed' => Money::zero(), 'collected' => Money::zero(), 'refunded' => Money::zero()];

            foreach ($branches as $branchId) {
                $figures = $period[$day->toDateString()][$branchId];
                $row['orders'] += $figures['orders_count'];
                $row['patients'] += $figures['patients_count'];
                $row['billed'] = $row['billed']->plus($figures['billed_total']);
                $row['collected'] = $row['collected']->plus($figures['collected_total']);
                $row['refunded'] = $row['refunded']->plus($figures['refunded_total']);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * What was sold, by test. Cancelled tests and the tests inside a
     * package are left out; the package itself is counted.
     *
     * @return list<array{name: string, count: int, gross: Money, discount: Money, net: Money}>
     */
    public function byTest(string $from, string $to): array
    {
        return $this->orderItems($from, $to)
            ->selectRaw('fd_order_items.service_name as name, COUNT(*) as sold, SUM(fd_order_items.unit_price) as gross, SUM(fd_order_items.discount_amount) as discount, SUM(fd_order_items.net_amount) as net')
            ->groupBy('fd_order_items.service_name')
            ->orderByDesc('net')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'count' => (int) $row->sold,
                'gross' => MoneyCast::fromDatabase($row->gross),
                'discount' => MoneyCast::fromDatabase($row->discount),
                'net' => MoneyCast::fromDatabase($row->net),
            ])
            ->all();
    }

    /**
     * Business each referral doctor sent. "Self" is patients who came
     * without a referral.
     *
     * @return list<array{name: string, orders: int, net: Money}>
     */
    public function byDoctor(string $from, string $to): array
    {
        return $this->orderItems($from, $to)
            ->leftJoin('fd_referral_doctors', 'fd_referral_doctors.id', '=', 'fd_orders.referral_doctor_id')
            ->selectRaw('fd_referral_doctors.name as name, COUNT(DISTINCT fd_orders.id) as orders, SUM(fd_order_items.net_amount) as net')
            ->groupBy('fd_referral_doctors.name')
            ->orderByDesc('net')
            ->get()
            ->map(fn ($row): array => ['name' => $row->name ?? 'Self (no referral)', 'orders' => (int) $row->orders, 'net' => MoneyCast::fromDatabase($row->net)])
            ->all();
    }

    /**
     * Money each staff member received, by payment method.
     *
     * @return list<array{name: string, method: string, payments: int, amount: Money}>
     */
    public function byCashier(string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);

        return DB::connection('clinic')->table('fd_payments')
            ->leftJoin('users', 'users.id', '=', 'fd_payments.received_by')
            ->where('fd_payments.received_at', '>=', $start->utc())
            ->where('fd_payments.received_at', '<', $end->addDay()->utc())
            ->selectRaw('users.name as name, fd_payments.method as method, COUNT(*) as payments, SUM(fd_payments.amount) as amount')
            ->groupBy('users.name', 'fd_payments.method')
            ->orderBy('users.name')
            ->orderBy('fd_payments.method')
            ->get()
            ->map(fn ($row): array => ['name' => $row->name ?? '-', 'method' => $row->method, 'payments' => (int) $row->payments, 'amount' => MoneyCast::fromDatabase($row->amount)])
            ->all();
    }

    /** First day of the current month, and today, in the clinic's calendar. */
    public function thisMonth(): array
    {
        $today = $this->daily->today();

        return [$today->startOfMonth()->toDateString(), $today->toDateString()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(string $from, string $to): array
    {
        $timezone = $this->clinics->require()->timezone;
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->startOfDay();

        if ($end->isBefore($start)) {
            throw new DomainException('The end date is before the start date.');
        }

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            throw new DomainException('Choose a period of '.self::MAX_DAYS.' days or less.');
        }

        return [$start, $end];
    }

    private function orderItems(string $from, string $to)
    {
        [$start, $end] = $this->period($from, $to);

        // The lowest and highest order number in the period. Every order in
        // the period lies between them, so limiting the order items to that
        // range lets the database skip the items of all other orders. The
        // date conditions below still decide exactly what is counted.
        $bounds = DB::connection('clinic')->table('fd_orders')
            ->where('ordered_at', '>=', $start->utc())
            ->where('ordered_at', '<', $end->addDay()->utc())
            ->selectRaw('MIN(id) as first, MAX(id) as last')
            ->first();

        return DB::connection('clinic')->table('fd_order_items')
            ->join('fd_orders', 'fd_orders.id', '=', 'fd_order_items.order_id')
            ->whereBetween('fd_order_items.order_id', [(int) ($bounds->first ?? 0), (int) ($bounds->last ?? 0)])
            ->where('fd_orders.ordered_at', '>=', $start->utc())
            ->where('fd_orders.ordered_at', '<', $end->addDay()->utc())
            ->where('fd_orders.status', '!=', OrderStatus::Cancelled->value)
            ->where('fd_order_items.status', ItemStatus::Active->value)
            ->whereNull('fd_order_items.package_item_of');
    }
}
