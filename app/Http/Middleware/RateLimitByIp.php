<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits requests per IP before they are authenticated.
 *
 * Laravel's own throttle runs after the guard, which means a flood of requests carrying
 * invalid tokens is never counted. Each of those still costs a database lookup, so they are
 * counted here instead; authenticated requests are then limited again, per user.
 */
class RateLimitByIp
{
    private const DECAY_SECONDS = 60;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'mcp-ip:'.$request->ip();
        $maxAttempts = (int) config('chess.rate_limits.per_ip');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new ThrottleRequestsException('Too Many Attempts.', headers: [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
