<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckToken
{
    /**
     * JWT nằm trong cookie HttpOnly `token` → chuyển thành header Bearer cho guard `api`.
     * Request đã tự gửi Authorization (app khác / script) thì giữ nguyên.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('token');
        if ($token && !$request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
