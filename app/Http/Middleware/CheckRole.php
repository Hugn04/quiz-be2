<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {

        // return $role;
        if (auth()->user()->role == "ban") {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khóa',
            ], 403);
        }



        if (!auth()->check() || !in_array(auth()->user()->role, $roles)) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }
        return $next($request);
    }
}
