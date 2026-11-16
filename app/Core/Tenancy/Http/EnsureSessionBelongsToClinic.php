<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Http;

use App\Core\Auth\User;
use App\Core\Tenancy\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a signed-in session from one clinic being used on another clinic's
 * address.
 *
 * User IDs repeat between clinic databases, so a session cookie copied to a
 * different clinic's domain would otherwise sign in as whoever has the same
 * ID there. The clinic is written into the session at login and checked on
 * every request.
 */
final class EnsureSessionBelongsToClinic
{
    public const SESSION_KEY = 'clinic_id';

    public function __construct(private readonly ClinicContext $clinics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $wrongClinic = Auth::check() && $request->session()->get(self::SESSION_KEY) !== $this->clinics->id();

        // A user deactivated while signed in loses access on the next request.
        $deactivated = Auth::check() && Auth::user() instanceof User && ! Auth::user()->is_active;

        if ($wrongClinic || $deactivated) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login');
        }

        return $next($request);
    }
}
