<?php

declare(strict_types=1);

namespace App\Core\Modules;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: "module:finance" answers "not found" when the module is
 * disabled for the current clinic.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleState $state) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless($this->state->isEnabled($key), 404);

        return $next($request);
    }
}
