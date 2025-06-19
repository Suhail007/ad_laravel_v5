<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class GeoRestictionMiddleware
{
    /**
     * Handle User is Admin or not.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAdmin = false;
        $adminId = null;
        $adminName = 'Admin';

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $adminId = $user->id;
            $adminName = $user->name ?? 'Admin';
            foreach ($user->capabilities as $key => $value) {
                if ($key == 'administrator') {
                    $isAdmin = true;
                }
            }
        } catch (\Throwable $th) {
        }
        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey you are not Allowed']);
        }
        return $next($request);

    }
}
