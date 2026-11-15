<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Http;

use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicDomain;
use App\Core\Tenancy\ClinicManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the clinic for the request's domain and connects to its database.
 * Nothing that touches clinic data may run before this.
 */
final class ResolveClinic
{
    public function __construct(private readonly ClinicManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = ClinicDomain::normalize($request->getHost());

        $clinic = Clinic::query()
            ->whereIn('id', ClinicDomain::query()->select('clinic_id')->where('domain', $host))
            ->first();

        if ($clinic === null) {
            $this->manager->deactivate();
            abort(404, 'No clinic is registered at this address.');
        }

        if (! $clinic->status->isUsable()) {
            $this->manager->deactivate();

            return response()->view('errors.clinic-unavailable', ['clinic' => $clinic], 403);
        }

        $this->manager->activate($clinic);

        return $next($request);
    }
}
