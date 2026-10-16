<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id');
        if (! is_string($correlationId) || trim($correlationId) === '') {
            $correlationId = 'corr_' . Str::lower(Str::random(24));
            $request->headers->set('X-Correlation-Id', $correlationId);
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
