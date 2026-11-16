<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FrontDesk\Enums\InvoiceStatus;
use Modules\FrontDesk\Enums\OrderStatus;
use Modules\FrontDesk\Enums\RefundStatus;

/**
 * The front desk's figures for one branch on one calendar day, where the
 * day is the clinic's local day, not a UTC day.
 */
final class DailyFigures
{
    public function __construct(private readonly ClinicContext $clinics) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->clinics->require()->timezone)->startOfDay();
    }

    /**
     * @return array{orders_count: int, patients_count: int, billed_total: Money, collected_total: Money, refunded_total: Money, due_total: Money}
     */
    public function for(CarbonImmutable $localDate, int $branchId): array
    {
        $db = DB::connection('clinic');
        $start = CarbonImmutable::parse($localDate->toDateString(), $this->clinics->require()->timezone)->startOfDay()->utc();
        $range = [$start, $start->addDay()];
        $between = fn ($query, string $column) => $query->where($column, '>=', $range[0])->where($column, '<', $range[1]);

        $orders = $between($db->table('fd_orders'), 'ordered_at')
            ->where('branch_id', $branchId)
            ->where('status', '!=', OrderStatus::Cancelled->value);

        $billed = $between($db->table('fd_invoice_lines'), 'fd_invoice_lines.created_at')
            ->join('fd_invoices', 'fd_invoices.id', '=', 'fd_invoice_lines.invoice_id')
            ->where('fd_invoices.branch_id', $branchId)
            ->where('fd_invoices.status', '!=', InvoiceStatus::Void->value)
            ->sum('fd_invoice_lines.amount');

        $collected = $between($db->table('fd_payments'), 'received_at')->where('branch_id', $branchId)->sum('amount');

        $refunded = $between($db->table('fd_refunds'), 'decided_at')
            ->where('branch_id', $branchId)
            ->where('status', RefundStatus::Approved->value)
            ->sum('amount');

        $due = $between($db->table('fd_invoices'), 'issued_at')
            ->where('branch_id', $branchId)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->sum('due_amount');

        return [
            'orders_count' => (clone $orders)->count(),
            'patients_count' => (clone $orders)->distinct()->count('patient_id'),
            'billed_total' => MoneyCast::fromDatabase($billed),
            'collected_total' => MoneyCast::fromDatabase($collected),
            'refunded_total' => MoneyCast::fromDatabase($refunded),
            'due_total' => MoneyCast::fromDatabase($due),
        ];
    }

    /** Store the day's figures. Safe to run again; the row is replaced. */
    public function store(CarbonImmutable $localDate, int $branchId): void
    {
        $figures = $this->for($localDate, $branchId);

        DB::connection('clinic')->table('fd_daily_summaries')->upsert(
            [[
                'summary_date' => $localDate->toDateString(),
                'branch_id' => $branchId,
                'orders_count' => $figures['orders_count'],
                'patients_count' => $figures['patients_count'],
                'billed_total' => $figures['billed_total']->toDecimal(),
                'collected_total' => $figures['collected_total']->toDecimal(),
                'refunded_total' => $figures['refunded_total']->toDecimal(),
                'due_total' => $figures['due_total']->toDecimal(),
                'rebuilt_at' => now(),
            ]],
            ['summary_date', 'branch_id'],
            ['orders_count', 'patients_count', 'billed_total', 'collected_total', 'refunded_total', 'due_total', 'rebuilt_at'],
        );
    }
}
