<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;

class CheckTokenExpiry extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken) {
            foreach (Arr::wrap(config('sanctum.guard', 'web')) as $sessionGuard) {
                if ($this->auth->guard($sessionGuard)->check()) {
                    return parent::handle($request, $next, ...$guards);
                }
            }

            // Read the previous activity before Sanctum refreshes last_used_at.
            $model = Sanctum::$personalAccessTokenModel;
            if (str_contains($bearerToken, '|') && (new $model)->getKeyType() === 'int') {
                [$id, $secret] = explode('|', $bearerToken, 2);

                if (!ctype_digit($id) || empty($secret)) {
                    return parent::handle($request, $next, ...$guards);
                }
            }

            $token = $model::findToken($bearerToken);
            $lastActivity = $token ? ($token->last_used_at ?? $token->created_at) : null;
            $idleMinutes = max(1, (int) config('sanctum.idle_timeout', 10));

            if ($lastActivity && $lastActivity->lte(now()->subMinutes($idleMinutes))) {
                return response()->json([
                    'message' => 'Session expired. Please login again.',
                ], 401);
            }
        }

        return parent::handle($request, $next, ...$guards);
    }
}
