<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|null  $ttl
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $ttl = 60)
    {
        // Skip caching for non-GET requests
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        // Generate cache key based on request
        $cacheKey = 'api_' . md5($request->fullUrl());

        // Return cached response if exists
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get response
        $response = $next($request);

        // Cache successful responses
        if ($response->status() === 200) {
            Cache::put($cacheKey, $response, $ttl);
        }

        return $response;
    }
} 