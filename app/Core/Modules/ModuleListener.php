<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Base class for a module's event listeners. The listener does nothing when
 * its module is disabled for the current clinic.
 */
abstract class ModuleListener
{
    /** The key of the module this listener belongs to. */
    abstract protected function moduleKey(): string;

    /** The reaction itself. Must be safe to run twice for the same event. */
    abstract protected function process(object $event): void;

    final public function handle(object $event): void
    {
        if (app(ModuleState::class)->isEnabled($this->moduleKey())) {
            $this->process($event);
        }
    }
}
