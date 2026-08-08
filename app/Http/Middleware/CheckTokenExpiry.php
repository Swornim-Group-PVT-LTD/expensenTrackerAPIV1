<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckTokenExpiry
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if ($token) {
            $tokenId = explode('|', $token)[0];
            $tokenRecord = DB::table('personal_access_tokens')->where('id', $tokenId)->first();

            if ($tokenRecord && $tokenRecord->expires_at && now()->greaterThan($tokenRecord->expires_at)) {
                // Delete expired token
                DB::table('personal_access_tokens')->where('id', $tokenId)->delete();

                return response()->json([
                    'message' => 'Session expired. Please login again.'
                ], 401);
            }
        }

        return $next($request);
    }
}