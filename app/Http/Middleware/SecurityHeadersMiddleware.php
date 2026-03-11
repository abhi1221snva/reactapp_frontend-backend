<?php
namespace App\Http\Middleware;

use Closure;

/**
 * SecurityHeadersMiddleware
 *
 * Injects defensive HTTP security headers on every response.
 * Registered as a global middleware in bootstrap/app.php.
 *
 * Headers set:
 *   X-Content-Type-Options   — prevent MIME-type sniffing
 *   X-Frame-Options          — deny framing (clickjacking protection)
 *   X-XSS-Protection         — legacy XSS filter for older browsers
 *   Referrer-Policy          — limit referrer leakage to same-origin
 */
class SecurityHeadersMiddleware
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}