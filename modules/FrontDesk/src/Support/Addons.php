<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Support;

use Modules\FrontDesk\Models\Service;

/**
 * The supplies (tube, needle) that go with the chosen services. The billing
 * screen shows them before saving, and placing the order adds them, so both
 * use this one rule: each supply once per order, however many tests need it,
 * and none that staff already chose or that are switched off.
 */
final class Addons
{
    /**
     * @param  list<Service>  $services  the services staff chose
     * @return list<Service> only the supplies to add after them
     */
    public function for(array $services): array
    {
        $chosen = array_flip(array_map(fn (Service $s): int => $s->id, $services));
        $needed = [];

        foreach ($services as $service) {
            foreach ($service->isPackage() ? $service->packageItems : [$service] as $part) {
                foreach ($part->addons()->where('is_active', true)->orderBy('fd_services.id')->get() as $addon) {
                    if (! isset($chosen[$addon->id]) && ! isset($needed[$addon->id])) {
                        $needed[$addon->id] = $addon;
                    }
                }
            }
        }

        return array_values($needed);
    }
}
