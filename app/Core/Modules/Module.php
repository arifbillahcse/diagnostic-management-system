<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Base class of a module's entry class. A module overrides the methods for
 * the things it adds; everything defaults to "nothing".
 */
abstract class Module
{
    public function __construct(public readonly ModuleManifest $manifest) {}

    /**
     * Permission keys this module defines.
     *
     * @return array<string, string> key => description
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Permissions given to the default roles when the module is first
     * installed in a clinic. The owner role always has everything.
     *
     * @return array<string, list<string>> role key => permission keys
     */
    public function defaultGrants(): array
    {
        return [];
    }

    /**
     * Numeric limits given to the default roles when the module is first
     * installed, for example a maximum discount percent. Existing values
     * are never overwritten.
     *
     * @return array<string, array<string, string>> role key => [limit key => value]
     */
    public function defaultLimits(): array
    {
        return [];
    }

    /** @return list<class-string> Filament resources */
    public function resources(): array
    {
        return [];
    }

    /** @return list<class-string> Filament pages */
    public function pages(): array
    {
        return [];
    }

    /** @return list<class-string> Filament dashboard widgets */
    public function widgets(): array
    {
        return [];
    }

    /** Register event listeners, routes, and scheduled tasks. */
    public function boot(): void {}
}
