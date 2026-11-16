<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Giới hạn các API xác thực (dùng ở routes/api.php: throttle:auth / throttle:otp).
         * Khóa theo EMAIL là chính: frontend gọi API qua proxy Next nên mọi request có thể
         * đến từ cùng 1 IP (server Next) → khóa theo IP chỉ để chặn dồn dập, đặt rộng.
         */
        $email = fn (Request $request) => strtolower(trim((string) $request->input('email')));

        // Đăng nhập / đăng ký / đặt lại mật khẩu: 10 lần / phút / email.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:' . $request->path() . ':' . $email($request)),
            Limit::perMinute(300)->by('auth-ip:' . $request->ip()),
        ]);

        // Gửi OTP qua email: 3 lần / 10 phút / email (chống spam mail).
        // Báo lỗi câu hỏi: tối đa 10 lần / 10 phút mỗi người.
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinutes(10, 10)->by('reports:' . (auth('api')->id() ?: $request->ip())));

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('otp:' . $request->path() . ':' . $email($request)),
            Limit::perMinute(60)->by('otp-ip:' . $request->ip()),
        ]);
    }
}
