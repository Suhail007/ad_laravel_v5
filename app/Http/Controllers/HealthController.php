<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        try {
            // Check database connection
            DB::connection()->getPdo();
            
            // Check Redis connection if configured
            if (config('cache.default') === 'redis') {
                Redis::connection()->ping();
            }
            
            return response()->json([
                'status' => 'ok',
                'services' => [
                    'database' => 'ok',
                    'redis' => config('cache.default') === 'redis' ? 'ok' : 'not_configured',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
