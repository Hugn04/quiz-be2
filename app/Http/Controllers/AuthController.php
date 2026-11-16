<?php

namespace App\Http\Controllers;

use App\Mail\OTPVerify;
use App\Mail\ForgotPassword;
use App\Models\User;
use App\Support\AuthCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Log;


class AuthController extends Controller
{

    /**
     * Trả JWT trong cookie HttpOnly `token` (middleware CheckToken đổi thành Bearer).
     * Cookie sống bằng hạn refresh để client còn gọi được /auth/refresh khi access token hết hạn.
     * Thuộc tính cookie: xem App\Support\AuthCookie.
     */
    private function respondWithToken(string $token, User $user, int $status = 200)
    {
        // Role administrator luôn khớp ADMINISTRATOR_EMAIL (đăng nhập / refresh là đồng bộ lại).
        $user->syncAdministratorRole();
        return response()
            ->json([
                'user' => $user,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ], $status)
            ->withCookie(AuthCookie::make($token));
    }

    /** Phản hồi 403 cho tài khoản bị khóa (đăng nhập / refresh). */
    private function bannedResponse(User $user)
    {
        return response()->json([
            'message' => 'Tài khoản của bạn đã bị khóa' . ($user->ban_reason ? ': ' . $user->ban_reason : '.'),
            'code' => 'banned',
        ], 403);
    }

    /** POST /auth/login */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $token = auth('api')->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        if (!$token) {
            return response()->json(['message' => "Tên tài khoản hoặc mật khẩu không chính xác"], 401);
        }

        $user = auth('api')->user();
        if ($user->isBanned()) {
            auth('api')->setToken($token)->invalidate();

            return $this->bannedResponse($user);
        }

        return $this->respondWithToken($token, $user);
    }

    /**
     * POST /auth/refresh — đổi JWT (kể cả đã hết hạn, còn trong hạn refresh) lấy JWT mới.
     * Token cũ bị đưa vào blacklist.
     */
    public function refresh()
    {
        try {
            $token = auth('api')->refresh();
            $guard = auth('api')->setToken($token);
            $user = $guard->user();
        } catch (\Throwable $e) {
            // KHÔNG xóa cookie: lỗi này thường do token gửi lên đã được refresh ở nơi khác
            // (tab khác / SSR / request song song) → trình duyệt có thể đang giữ token MỚI hợp lệ,
            // xóa cookie sẽ đá người dùng ra. Hết phiên thật thì client tự về trang đăng nhập.
            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.'], 401);
        }
        if ($user && $user->isBanned()) {
            $guard->invalidate();

            return $this->bannedResponse($user);
        }
        // Token phát hành trước lần đổi mật khẩu gần nhất → không cho làm mới.
        if (!$user || (int) $guard->payload()->get('ver') !== (int) $user->token_version) {
            $guard->invalidate();
            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.'], 401);
        }

        return $this->respondWithToken($token, $user);
    }
    /**
     * POST /auth/google — đăng nhập Google.
     * Nhận `access_token` (nút tự vẽ, useGoogleLogin) hoặc `id_token` (nút GoogleLogin cũ).
     * Token phải được cấp cho đúng Client ID của app (`aud`), nếu không thì token
     * của một ứng dụng khác cũng đăng nhập được vào tài khoản cùng email.
     */
    public function googleLogin(Request $request)
    {
        $clientId = config('services.google.client_id');
        if (!$clientId) {
            Log::error('Thiếu GOOGLE_CLIENT_ID trong .env');
            return response()->json(['message' => 'Máy chủ chưa cấu hình đăng nhập Google'], 500);
        }

        $profile = $request->filled('access_token')
            ? $this->googleProfileFromAccessToken($request->input('access_token'), $clientId)
            : ($request->filled('id_token')
                ? $this->googleProfileFromIdToken($request->input('id_token'), $clientId)
                : null);

        if (!$profile) {
            return response()->json(['message' => 'Phiên đăng nhập Google không hợp lệ, vui lòng thử lại'], 401);
        }

        $user = User::firstOrCreate(
            ['email' => $profile['email']],
            [
                'name' => $profile['name'] ?: strstr($profile['email'], '@', true),
                'role' => 'person',
                'email_verified_at' => now(),
            ]
        );

        if ($user->isBanned()) {
            return $this->bannedResponse($user);
        }

        return $this->respondWithToken(auth('api')->login($user), $user);
    }

    /** Xác thực access_token → ['email', 'name'] hoặc null. */
    private function googleProfileFromAccessToken(string $accessToken, string $clientId): ?array
    {
        $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'access_token' => $accessToken,
        ]);
        if (!$info->ok() || $info->json('aud') !== $clientId) {
            return null;
        }

        $user = Http::timeout(10)->withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');
        if (!$user->ok() || !$user->json('email') || $user->json('email_verified') !== true) {
            return null;
        }

        return ['email' => $user->json('email'), 'name' => $user->json('name')];
    }

    /** Xác thực id_token → ['email', 'name'] hoặc null. */
    private function googleProfileFromIdToken(string $idToken, string $clientId): ?array
    {
        $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);
        if (
            !$info->ok()
            || $info->json('aud') !== $clientId
            || !in_array($info->json('iss'), ['accounts.google.com', 'https://accounts.google.com'], true)
            || !$info->json('email')
            // tokeninfo trả email_verified dạng chuỗi "true"
            || !filter_var($info->json('email_verified'), FILTER_VALIDATE_BOOLEAN)
        ) {
            return null;
        }

        return ['email' => $info->json('email'), 'name' => $info->json('name')];
    }

    /** POST /auth/register/otp — gửi OTP xác nhận email đăng ký. */
    public function sendRegisterOtp(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = $data['email'];
        $user = User::where('email', $email)->first();
        if ($user && $user->password) {
            return response()->json('Email này đã được đăng ký rồi !', 409);
        } else {
            $otp = $this->generateOTP();
            Cache::put('otp_' . $email, $otp, now()->addMinutes(5));
            Cache::put('otp_' . $email . '_attempts', 0, now()->addMinutes(5));
            Mail::to($email)->send(new OTPVerify($otp));
            return response()->json("Đã gửi email tới " . $email . ". Vui lòng kiểm tra email!");
        }
    }
    /** POST /auth/register — xác nhận OTP + tạo tài khoản. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);
        $email = $data['email'];
        $otp = $data['otp'];

        $dataOTP = $this->verifyOTP($email, $otp);
        if ($dataOTP === true) {
            $password = bcrypt($data["password"]);
            $name = $data["name"];
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update([
                    "password" => $password,
                    'email_verified_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $user = User::create([
                    "name" => $name,
                    "email" => $email,
                    "password" => $password,
                    "role" => "person",
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            return response()->json('Tạo tài khoản thành công !');
        }
        return $dataOTP;
    }
    /** POST /auth/password/reset/otp — gửi OTP quên mật khẩu. */
    public function sendResetPasswordOtp(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = $data['email'];
        $account = DB::table("users")->where("email", $email)->get();
        if ($account->count()) {
            $otp = $this->generateOTP();
            Cache::put('otp_' . $email, $otp, now()->addMinutes(5));
            Cache::put('otp_' . $email . '_attempts', 0, now()->addMinutes(5));
            Mail::to($data['email'])->send(new OTPVerify($otp));
            return response()->json("");
        } else {
            return response()->json('Email không tồn tại !', 404);
        }
    }
    /** POST /auth/password/reset — xác nhận OTP, đặt mật khẩu ngẫu nhiên và gửi qua email. */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
        ]);
        $email = $data['email'];
        $otp = $data['otp'];
        $dataOTP = $this->verifyOTP($email, $otp);
        if ($dataOTP === true) {
            $password = $this->generateRandomPassword(6);
            User::where('email', $email)->update([
                'password' => bcrypt($password),
            ]);
            // Thu hồi mọi JWT cũ của tài khoản.
            User::where('email', $email)->increment('token_version');
            Mail::to($data['email'])->send(new ForgotPassword($password));
            return response()->json('Mật khẩu của bạn đã được gửi đến ' . $email);
        }
        return $dataOTP;
    }

    /** PUT /auth/password — đổi mật khẩu (cần mật khẩu cũ). */
    public function changePassword(Request $request)
    {

        $oldPassword = $request->input('oldPassword');
        $newPassword = $request->input('newPassword');
        if (strlen($newPassword) >= 6 && $oldPassword) {
            $user = User::find(auth()->user()->id);
            if ($user->password === null) {
                return response()->json('Bạn chưa đăng ký tài khoản này !', 422);
            }
            if (Hash::check($oldPassword, $user->password)) {
                $user->update([
                    'password' => Hash::make($newPassword)
                ]);
                // Thu hồi mọi JWT của tài khoản (mọi thiết bị) + token hiện tại.
                $user->increment('token_version');
                auth('api')->invalidate();
                return response()->json('Đổi mật khẩu thành công. Vui lòng đăng nhập lại !')
                    ->withCookie(AuthCookie::forget());
            } else {
                return response()->json('Bạn nhập lại mật khẩu cũ bị sai !', 422);
            }
        } else {
            return response()->json("Bạn chưa nhập đủ mật khẩu", 422);
        }
    }
    /** Tối đa số lần nhập sai cho 1 mã OTP (chống dò 6 chữ số). */
    private const OTP_MAX_ATTEMPTS = 5;

    private function verifyOTP($email, $otp)
    {
        $key = 'otp_' . $email;
        $cachedOtp = Cache::get($key);
        if (!$cachedOtp) {
            return response()->json('OTP đã hết hạn', 410);
        }
        if (hash_equals((string) $cachedOtp, (string) $otp)) {
            Cache::forget($key);
            Cache::forget($key . '_attempts');
            return true;
        }
        $attempts = Cache::increment($key . '_attempts');
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            // Sai quá nhiều → hủy mã, phải gửi lại OTP mới.
            Cache::forget($key);
            Cache::forget($key . '_attempts');
            return response()->json('Nhập sai OTP quá nhiều lần, vui lòng gửi lại mã mới.', 410);
        }
        return response()->json('OTP không hợp lệ vui lòng nhập lại !', 422);
    }

    function generateRandomPassword($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomPassword = '';

        for ($i = 0; $i < $length; $i++) {
            $randomPassword .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomPassword;
    }
    private function generateOTP($length = 6)
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }
    /** GET /auth/me — người dùng đang đăng nhập. */
    public function me()
    {
        return response()->json(auth('api')->user());
    }
    /** POST /auth/logout */
    public function logout()
    {
        auth('api')->logout(); // đưa token hiện tại vào blacklist

        return response()->json("Đăng xuất thành công")->withCookie(AuthCookie::forget());
    }
}
