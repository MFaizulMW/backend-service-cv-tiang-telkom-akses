<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects all Admin API endpoints with X-Admin-Key header authentication.
 * Key comes from ADMIN_API_KEY environment variable — never hardcoded.
 */
class AdminApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminKey = (string) config('telkom.admin_api_key');

        if (! $adminKey) {
            abort(500, 'Admin API key is not configured');
        }

        $provided = $request->header('X-Admin-Key', '');

        if (! hash_equals($adminKey, $provided)) {
            abort(401, 'Unauthorized: invalid or missing X-Admin-Key');
        }

        return $next($request);
    }
}
