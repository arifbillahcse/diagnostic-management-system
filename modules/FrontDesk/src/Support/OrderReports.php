<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use Modules\FrontDesk\Models\Order;

/**
 * The reports of an order, shown on the order page: what is ready, what is
 * waiting, and a print button for what is ready. A module that requires
 * the front desk (lab reports, imaging) adds its rows from its boot().
 */
final class OrderReports
{
    /** @var list<callable(Order): list<array{label: string, status: string, color: string, url: ?string, order?: int}>> */
    private array $providers = [];

    /** @param callable(Order): list<array{label: string, status: string, color: string, url: ?string, order?: int}> $provider */
    public function add(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<array{label: string, status: string, color: string, url: ?string, order?: int}> */
    public function for(Order $order): array
    {
        $rows = [];

        foreach ($this->providers as $provider) {
            array_push($rows, ...$provider($order));
        }

        usort($rows, fn (array $a, array $b): int => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));

        return $rows;
    }
}
