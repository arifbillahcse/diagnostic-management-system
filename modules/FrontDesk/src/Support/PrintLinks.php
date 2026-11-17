<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use Modules\FrontDesk\Models\Order;

/**
 * What can be printed for a bill after it is saved. The front desk adds the
 * invoice itself; a module that requires the front desk (the laboratory, for
 * sample labels) adds its own by registering a callback in its boot().
 */
final class PrintLinks
{
    /** @var list<callable(Order): ?array{key: string, label: string, url: string}> */
    private array $providers = [];

    /** @param callable(Order): ?array{key: string, label: string, url: string} $provider returns null when there is nothing to print */
    public function add(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<array{key: string, label: string, url: string}> */
    public function for(Order $order): array
    {
        $links = [];

        foreach ($this->providers as $provider) {
            if (($link = $provider($order)) !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
