<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Switches the "clinic" database connection to a given clinic.
 */
final class ClinicManager
{
    public const UNRESOLVED = ['driver' => 'clinic-not-resolved'];

    public function __construct(
        private readonly ClinicContext $context,
        private readonly ClinicDatabase $databases,
    ) {}

    public function activate(Clinic $clinic): void
    {
        config(['database.connections.clinic' => $this->databases->connectionConfig($clinic)]);
        DB::purge('clinic');
        $this->context->set($clinic);

        event(new ClinicSwitched($clinic));
    }

    /**
     * Leave no clinic active. Any query on the clinic connection now fails,
     * which is the intended safety net.
     */
    public function deactivate(): void
    {
        config(['database.connections.clinic' => self::UNRESOLVED]);
        DB::purge('clinic');
        $this->context->clear();

        event(new ClinicSwitched(null));
    }

    /**
     * Run a callback for one clinic, then restore whatever was active before.
     *
     * @template T
     *
     * @param  Closure(Clinic): T  $callback
     * @return T
     */
    public function run(Clinic $clinic, Closure $callback): mixed
    {
        $previous = $this->context->get();
        $this->activate($clinic);

        try {
            return $callback($clinic);
        } finally {
            $previous ? $this->activate($previous) : $this->deactivate();
        }
    }
}
