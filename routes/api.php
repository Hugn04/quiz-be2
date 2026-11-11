<?php

use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExampleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API RESTful
|--------------------------------------------------------------------------
| - Danh từ số nhiều cho tài nguyên (/examples, /users, /sectors...).
| - Hành động thể hiện bằng HTTP method (GET đọc, POST tạo, PUT/PATCH sửa, DELETE xóa).
| - Định danh trên path là SLUG (/examples/mang-may-tinh, /users/hung2k4hytq),
|   bộ lọc/phân trang nằm ở query string (?sector=<slug ngành>&page=2).
| - `me` = người dùng đang đăng nhập.
*/

// ----- Công khai -----
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'googleLogin']);

    // Đăng ký: gửi OTP → xác nhận OTP + tạo tài khoản
    Route::post('register/otp', [AuthController::class, 'sendRegisterOtp']);
    Route::post('register', [AuthController::class, 'register']);

    // Quên mật khẩu: gửi OTP → xác nhận OTP + gửi mật khẩu mới qua email
    Route::post('password/reset/otp', [AuthController::class, 'sendResetPasswordOtp']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);

    // Đổi JWT (có thể đã hết hạn, còn trong hạn refresh) lấy JWT mới
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::get('examples', [ExampleController::class, 'index']);
Route::get('examples/trending', [HomeController::class, 'trending']);
Route::get('sectors', [SectorController::class, 'index']);
Route::get('stats', [HomeController::class, 'stats']);

// ----- Cần đăng nhập -----
// auth:api = JWT hợp lệ; token.version = chưa bị thu hồi do đổi mật khẩu
Route::middleware(['auth:api', 'token.version'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });

    // Người dùng hiện tại
    Route::prefix('users/me')->group(function () {
        Route::get('/', [UserController::class, 'me']);
        Route::patch('/', [UserController::class, 'updateMe']);
        Route::get('examples', [ExampleController::class, 'mine']);
        Route::get('favorites', [ExampleController::class, 'favorites']);
        Route::get('attempts', [AttemptController::class, 'mine']);
    });
    Route::get('users', [UserController::class, 'index']); // ?ids=1,2,3
    Route::get('users/{user:slug}', [UserController::class, 'show']);

    // Đề thi
    Route::post('examples', [ExampleController::class, 'store']);
    Route::get('examples/{example:slug}', [ExampleController::class, 'show']);
    Route::put('examples/{example:slug}', [ExampleController::class, 'update']);
    Route::delete('examples/{example:slug}', [ExampleController::class, 'destroy']);
    Route::put('examples/{example:slug}/like', [ExampleController::class, 'like']);
    Route::put('examples/{example:slug}/favorite', [ExampleController::class, 'favorite']);

    // Lượt làm bài + bảng xếp hạng của 1 đề
    Route::get('examples/{example:slug}/leaderboard', [AttemptController::class, 'index']);
    Route::post('examples/{example:slug}/attempts', [AttemptController::class, 'store']);
    Route::get('attempts/{attempt:ulid}', [AttemptController::class, 'show']);

    // Nhập đề từ EduQuiz (admin)
    Route::get('eduquiz-exams/{tag}', [ExampleController::class, 'importFromEduquiz']);

    // Upload ảnh lên Cloudinary
    Route::post('images', [ImageController::class, 'store']);
});
