<?php

declare(strict_types=1);

namespace App\Core\Modules;

use DomainException;

/**
 * A module operation that was refused. Messages are written for the clinic
 * admin and are safe to show on screen.
 */
class ModuleException extends DomainException
{
    public static function unknown(string $key): self
    {
        return new self("There is no module called \"{$key}\".");
    }

    public static function notEntitled(string $name): self
    {
        return new self("{$name} is not included in this clinic's plan.");
    }

    /** @param list<string> $names */
    public static function missingRequirements(string $name, array $names): self
    {
        return new self("{$name} needs these modules enabled first: ".implode(', ', $names).'.');
    }

    /** @param list<string> $names */
    public static function hasDependents(string $name, array $names): self
    {
        return new self("{$name} cannot be disabled while these modules use it: ".implode(', ', $names).'.');
    }
}
