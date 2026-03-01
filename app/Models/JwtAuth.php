<?php
namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['error' => 'Missing token'], 401);
        }
        $token = substr($auth, 7);

        try {
            $payload = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            $request->attributes->set('user_id', $payload->sub ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
        return $next($request);
    }
}
