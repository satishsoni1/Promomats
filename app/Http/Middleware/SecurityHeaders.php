<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers Laravel doesn't set by default. None of these require
 * app-specific tuning, so they're safe to apply globally rather than left as a
 * deployment-time checklist item.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // This app is never meant to be embedded in another site's <iframe> - blocking
        // it outright removes clickjacking as a concern rather than tuning it per page.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Browser-level lockout for APIs this app never uses - camera/mic/geolocation
        // have no legitimate reason to be requestable from any page here.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
