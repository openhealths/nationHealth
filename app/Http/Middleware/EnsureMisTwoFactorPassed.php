<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMisTwoFactorPassed
{
    /**
     * Number of minutes the MIS two-factor gate stays valid after verification.
     */
    private const int GATE_TTL_MINUTES = 10;

    /**
     * Allow the request only when the MIS two-factor step was passed recently.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isGateValid($request->session()->get('mis_2fa'))) {
            $request->session()->forget('mis_2fa');
            $request->session()->reflash();

            return redirect()->route('mis.login');
        }

        return $next($request);
    }

    /**
     * Determine whether the stored gate payload is present and still fresh.
     *
     * @param  array{user_id: int, verified_at: string}|null  $gate
     * @return bool
     */
    private function isGateValid(?array $gate): bool
    {
        if (empty($gate['verified_at'])) {
            return false;
        }

        return CarbonImmutable::parse($gate['verified_at'])->gt(now()->subMinutes(self::GATE_TTL_MINUTES));
    }
}
