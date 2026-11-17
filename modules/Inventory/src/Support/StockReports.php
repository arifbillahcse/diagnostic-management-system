<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Shortfall;

/**
 * Read-only figures for the alerts and reports.
 */
final class StockReports
{
    public function __construct(private readonly ClinicContext $clinics) {}

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->clinics->timezone())->startOfDay();
    }

    /** @return array{value: Money, low: int, expiring: int, expired: int, shortfalls: int} */
    public function alerts(): array
    {
        $today = $this->today();

        return [
            'value' => MoneyCast::fromDatabase(Item::where('is_active', true)->sum('stock_value')),
            'low' => Item::where('is_active', true)->whereColumn('quantity', '<=', 'reorder_level')->count(),
            'expiring' => count(array_filter($this->batches(), fn (array $b): bool => $b['expires_on'] >= $today->toDateString() && $b['expires_on'] <= $today->addDays(Enums::EXPIRY_WARNING_DAYS)->toDateString())),
            'expired' => count(array_filter($this->batches(), fn (array $b): bool => $b['expires_on'] < $today->toDateString())),
            'shortfalls' => Shortfall::whereNull('resolved_at')->count(),
        ];
    }

    /**
     * Every batch still holding stock that has an expiry date, soonest first.
     *
     * @return list<array{item_id: int, item: string, code: string, unit: string, batch_no: string, expires_on: string, quantity: Quantity, days: int}>
     */
    public function batches(): array
    {
        $today = $this->today();
        $rows = DB::connection('clinic')->table('inv_movements as m')
            ->join('inv_items as i', 'i.id', '=', 'm.item_id')
            ->selectRaw('m.item_id, i.name, i.code, i.unit, m.batch_no, MIN(m.expires_on) as expires_on, SUM(m.quantity) as q')
            ->whereNotNull('m.batch_no')
            ->whereNotNull('m.expires_on')
            ->groupBy('m.item_id', 'i.name', 'i.code', 'i.unit', 'm.batch_no')
            ->get();

        $list = [];

        foreach ($rows as $row) {
            $quantity = Quantity::fromDecimal(is_string($row->q) ? $this->three($row->q) : number_format((float) $row->q, 3, '.', ''));

            if (! $quantity->isPositive()) {
                continue;
            }

            $expires = CarbonImmutable::parse($row->expires_on, $today->timezone);
            $list[] = [
                'item_id' => (int) $row->item_id,
                'item' => $row->name,
                'code' => $row->code,
                'unit' => $row->unit,
                'batch_no' => $row->batch_no,
                'expires_on' => $expires->toDateString(),
                'quantity' => $quantity,
                'days' => (int) $today->diffInDays($expires, false),
            ];
        }

        usort($list, fn (array $a, array $b): int => [$a['expires_on'], $a['item']] <=> [$b['expires_on'], $b['item']]);

        return $list;
    }

    /**
     * Stock used by tests in a period of local days, net of what cancelled tests put back.
     *
     * @return list<array{test: string, times: int, value: Money}>
     */
    public function useByTest(string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);

        $rows = DB::connection('clinic')->table('inv_movements as m')
            ->join('fd_order_items as oi', 'oi.id', '=', 'm.source_id')
            ->where('m.source_type', 'order_item')
            ->whereIn('m.reason', ['use', 'unuse'])
            ->whereBetween('m.occurred_at', [$start, $end])
            ->selectRaw('oi.service_name, COUNT(DISTINCT CASE WHEN m.reason = ? THEN oi.id END) as times, SUM(m.value) as v', ['use'])
            ->groupBy('oi.service_name')
            ->get();

        $list = [];

        foreach ($rows as $row) {
            $list[] = ['test' => $row->service_name, 'times' => (int) $row->times, 'value' => MoneyCast::fromDatabase($row->v)->negate()];
        }

        usort($list, fn (array $a, array $b): int => $b['value']->minor <=> $a['value']->minor);

        return $list;
    }

    /**
     * Stock that went out in a period, by item and reason (net of put-backs).
     *
     * @return list<array{item: string, unit: string, used: Quantity, other: Quantity, value: Money}>
     */
    public function outByItem(string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);

        $rows = DB::connection('clinic')->table('inv_movements as m')
            ->join('inv_items as i', 'i.id', '=', 'm.item_id')
            ->whereIn('m.reason', ['use', 'unuse', 'issue', 'waste', 'expired'])
            ->whereBetween('m.occurred_at', [$start, $end])
            ->selectRaw("i.name, i.unit, SUM(CASE WHEN m.reason IN ('use', 'unuse') THEN m.quantity ELSE 0 END) as used, SUM(CASE WHEN m.reason IN ('issue', 'waste', 'expired') THEN m.quantity ELSE 0 END) as other, SUM(m.value) as v")
            ->groupBy('i.name', 'i.unit')
            ->get();

        $list = [];

        foreach ($rows as $row) {
            $list[] = [
                'item' => $row->name,
                'unit' => $row->unit,
                'used' => $this->quantity($row->used)->negate(),
                'other' => $this->quantity($row->other)->negate(),
                'value' => MoneyCast::fromDatabase($row->v)->negate(),
            ];
        }

        usort($list, fn (array $a, array $b): int => $b['value']->minor <=> $a['value']->minor);

        return $list;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds of a period of local days */
    private function period(string $from, string $to): array
    {
        $tz = $this->clinics->timezone();

        return [CarbonImmutable::parse($from, $tz)->startOfDay()->utc(), CarbonImmutable::parse($to, $tz)->endOfDay()->utc()];
    }

    private function quantity(mixed $sum): Quantity
    {
        return Quantity::fromDecimal($sum === null ? '0' : (is_string($sum) ? $this->three($sum) : number_format((float) $sum, 3, '.', '')));
    }

    private function three(string $value): string
    {
        return preg_match('/^(-?\d+)(?:\.(\d+))?$/', $value, $m) ? $m[1].'.'.substr(str_pad($m[2] ?? '', 3, '0'), 0, 3) : '0';
    }
}
