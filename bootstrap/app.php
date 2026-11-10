<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckToken;
use App\Http\Middleware\EnsureTokenVersion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(middleware: CheckToken::class);
        // API không có trang đăng nhập → chưa xác thực thì trả 401 JSON, không redirect.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/login');
        $middleware->alias([
            'checkRole' => CheckRole::class,
            'token.version' => EnsureTokenVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API luôn trả lỗi dạng JSON (kể cả request không gửi Accept: application/json,
        // vd navigator.sendBeacon) → không bị redirect sang route "login" không tồn tại.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();
