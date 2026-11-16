<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Core\Branches\Branch;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Support\DailyFigures;

/**
 * Money in and money out over a period, on a cash basis: what was actually
 * received and actually paid, on the clinic's local dates.
 */
final class ProfitReport
{
    public function __construct(
        private readonly ClinicContext $clinics,
        private readonly DailyFigures $daily,
    ) {}

    /**
     * @return array{
     *     from: CarbonImmutable, to: CarbonImmutable,
     *     collected: Money, refunded: Money, expenses: Money, commission_paid: Money, net: Money,
     *     billed: Money, commission_earned: Money, commission_owed: Money, dues: Money,
     *     by_category: list<array{name: string, amount: Money}>,
     *     by_method: list<array{method: string, amount: Money}>
     * }
     */
    public function for(string $from, string $to): array
    {
        $timezone = $this->clinics->require()->timezone;
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->startOfDay();

        if ($end->isBefore($start)) {
            throw new FinanceException('The end date is before the start date.');
        }

        if ($start->diffInDays($end) > 366) {
            throw new FinanceException('Choose a period of one year or less.');
        }

        $db = DB::connection('clinic');
        $range = [$start->utc(), $end->addDay()->utc()];
        $between = fn ($query, string $column) => $query->where($column, '>=', $range[0])->where($column, '<', $range[1]);
        $money = fn ($value): Money => MoneyCast::fromDatabase($value);

        // Collected, refunded, and billed come from the front desk's daily
        // figures: stored for finished days, live for today. Adding up a
        // year of payments and invoice lines row by row took seconds.
        $collected = $refunded = $billed = Money::zero();

        foreach ($this->daily->forPeriod($start, $end, Branch::pluck('id')->map(fn ($id): int => (int) $id)->all()) as $branches) {
            foreach ($branches as $figures) {
                $collected = $collected->plus($figures['collected_total']);
                $refunded = $refunded->plus($figures['refunded_total']);
                $billed = $billed->plus($figures['billed_total']);
            }
        }

        $commissionPaid = $money($between($db->table('fin_commission_payouts'), 'paid_at')->sum('amount'));

        $expenseQuery = fn () => $db->table('fin_expenses')->whereNull('voided_at')
            // "From the start day, before the day after the end day" works
            // whether the database stores the date with or without a time,
            // and keeps the index usable.
            ->where('expense_date', '>=', $start->toDateString())
            ->where('expense_date', '<', $end->addDay()->toDateString());
        $expenses = $money($expenseQuery()->sum('amount'));

        $byCategory = $expenseQuery()
            ->join('fin_expense_categories', 'fin_expense_categories.id', '=', 'fin_expenses.category_id')
            ->selectRaw('fin_expense_categories.name as name, SUM(fin_expenses.amount) as total')
            ->groupBy('fin_expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['name' => $row->name, 'amount' => $money($row->total)])
            ->all();

        $byMethod = $between($db->table('fd_payments'), 'received_at')
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['method' => $row->method, 'amount' => $money($row->total)])
            ->all();

        $commissionEarned = $money($between($db->table('fin_commission_entries'), 'created_at')->sum('amount'));
        $commissionOwed = $money($db->table('fin_commission_entries')->sum('amount'))->minus($money($db->table('fin_commission_payouts')->sum('amount')));

        $dues = $money($db->table('fd_invoices')
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->sum('due_amount'));

        return [
            'from' => $start,
            'to' => $end,
            'collected' => $collected,
            'refunded' => $refunded,
            'expenses' => $expenses,
            'commission_paid' => $commissionPaid,
            'net' => $collected->minus($refunded)->minus($expenses)->minus($commissionPaid),
            'billed' => $billed,
            'commission_earned' => $commissionEarned,
            'commission_owed' => $commissionOwed,
            'dues' => $dues,
            'by_category' => $byCategory,
            'by_method' => $byMethod,
        ];
    }
}
