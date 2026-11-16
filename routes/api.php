<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExampleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\SettingController;
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
    // Giới hạn số lần thử (xem AppServiceProvider): auth = 10/phút/email, otp = 3/10 phút/email.
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('google', [AuthController::class, 'googleLogin']);
        // Đăng ký: gửi OTP → xác nhận OTP + tạo tài khoản
        Route::post('register', [AuthController::class, 'register']);
        // Quên mật khẩu: gửi OTP → xác nhận OTP + gửi mật khẩu mới qua email
        Route::post('password/reset', [AuthController::class, 'resetPassword']);
    });
    Route::middleware('throttle:otp')->group(function () {
        Route::post('register/otp', [AuthController::class, 'sendRegisterOtp']);
        Route::post('password/reset/otp', [AuthController::class, 'sendResetPasswordOtp']);
    });

    // Đổi JWT (có thể đã hết hạn, còn trong hạn refresh) lấy JWT mới
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::get('examples', [ExampleController::class, 'index']);
Route::get('examples/trending', [HomeController::class, 'trending']);
// Trang xem trước đề (công khai, cho khách + SEO): thông tin đề + câu mẫu, bảng xếp hạng.
Route::get('examples/{example:slug}/preview', [ExampleController::class, 'preview']);
Route::get('examples/{example:slug}/leaderboard', [AttemptController::class, 'index']);
Route::get('sectors', [SectorController::class, 'index']);
Route::get('stats', [HomeController::class, 'stats']);
Route::get('sitemap/examples', [ExampleController::class, 'sitemap']);
Route::get('users/{user:uuid}/card', [UserController::class, 'card'])->whereUuid('user');
// Cấu hình giao diện (màu chủ đạo, logo, ảnh mặc định) — công khai.
Route::get('settings', [SettingController::class, 'show']);

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
        // Ping giữ trạng thái online khi đang mở web (middleware token.version ghi last_seen_at).
        Route::post('heartbeat', fn () => response()->noContent())->middleware('throttle:10,1');
    });
    Route::get('users', [UserController::class, 'index']); // ?ids=1,2,3
    // Trang cá nhân theo UUID (không dùng slug: slug sinh từ email).
    Route::get('users/{user:uuid}', [UserController::class, 'show'])->whereUuid('user');

    // Đề thi
    Route::post('examples', [ExampleController::class, 'store']);
    Route::get('examples/{example:slug}', [ExampleController::class, 'show']);
    Route::put('examples/{example:slug}', [ExampleController::class, 'update']);
    Route::delete('examples/{example:slug}', [ExampleController::class, 'destroy']);
    Route::put('examples/{example:slug}/like', [ExampleController::class, 'like']);
    Route::put('examples/{example:slug}/favorite', [ExampleController::class, 'favorite']);
    Route::post('examples/{example:slug}/reports', [\App\Http\Controllers\QuestionReportController::class, 'store'])->middleware('throttle:reports');

    // Lượt làm bài + bảng xếp hạng của 1 đề
    Route::post('examples/{example:slug}/attempts', [AttemptController::class, 'store']);
    Route::get('attempts/{attempt:ulid}', [AttemptController::class, 'show']);

    // Nhập đề từ EduQuiz (admin)

    // ----- Trang quản trị -----
    // administrator + manager: tổng quan, quản lý mọi đề thi.
    Route::prefix('admin')->middleware('checkRole:administrator,manager')->group(function () {
        Route::get('stats', [AdminController::class, 'stats']);
        Route::get('online', [AdminController::class, 'online']);
        Route::get('examples', [AdminController::class, 'examples']);
        Route::get('examples/{example:slug}', [AdminController::class, 'showExample']);
        Route::put('examples/{example:slug}', [AdminController::class, 'updateExample']);
        Route::delete('examples/{example:slug}', [AdminController::class, 'destroyExample']);
        Route::patch('examples/{example:slug}/visibility', [AdminController::class, 'updateVisibility']);
        Route::patch('examples/{example:slug}/pin', [AdminController::class, 'updatePin']);
        Route::get('reports', [\App\Http\Controllers\QuestionReportController::class, 'index']);
        Route::get('reports/count', [\App\Http\Controllers\QuestionReportController::class, 'openCount']);
        Route::patch('reports/{report}', [\App\Http\Controllers\QuestionReportController::class, 'update'])->whereNumber('report');
        Route::get('analytics', \App\Http\Controllers\AnalyticsController::class);
        Route::get('sectors', [\App\Http\Controllers\AdminSectorController::class, 'index']);
        Route::post('sectors', [\App\Http\Controllers\AdminSectorController::class, 'store']);
        Route::put('sectors/order', [\App\Http\Controllers\AdminSectorController::class, 'reorder']);
        Route::put('sectors/{sector}', [\App\Http\Controllers\AdminSectorController::class, 'update'])->whereNumber('sector');
        Route::delete('sectors/{sector}', [\App\Http\Controllers\AdminSectorController::class, 'destroy'])->whereNumber('sector');
        Route::get('tools/export/examples/{example:slug}/attempts', [\App\Http\Controllers\AdminToolController::class, 'exportAttempts']);

        // Người dùng: xem + khóa / chặn tạo đề (manager chỉ với người dùng thường).
        Route::get('users', [AdminController::class, 'users']);
        Route::patch('users/{user:uuid}/status', [AdminController::class, 'updateStatus'])->whereUuid('user');

        // Chỉ administrator: cấp role, chỉnh giao diện site.
        Route::middleware('checkRole:administrator')->group(function () {
            Route::put('settings', [SettingController::class, 'update']);
            Route::get('logs', [AdminController::class, 'logs']);
            Route::get('import/eduquiz', [\App\Http\Controllers\AdminImportController::class, 'eduquiz'])->middleware('throttle:30,1');
            Route::post('import/eduquiz', [\App\Http\Controllers\AdminImportController::class, 'store'])->middleware('throttle:30,1');
            Route::post('tools/images/scan', [\App\Http\Controllers\AdminToolController::class, 'scanImages']);
            Route::post('tools/images/prune', [\App\Http\Controllers\AdminToolController::class, 'pruneImages']);
            Route::get('tools/export/users', [\App\Http\Controllers\AdminToolController::class, 'exportUsers']);
            Route::patch('users/{user:uuid}/role', [AdminController::class, 'updateRole'])->whereUuid('user');
        });
    });

    // Upload ảnh lên Cloudinary
    Route::post('images', [ImageController::class, 'store']);
});
